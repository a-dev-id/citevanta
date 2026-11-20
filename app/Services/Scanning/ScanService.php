<?php

namespace App\Services\Scanning;

use App\Enums\PromptSource;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\FinalizeScanRunJob;
use App\Jobs\ScanPromptJob;
use App\Models\Competitor;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Engines\EngineManager;
use App\Services\Insights\ScanInsights;
use App\Services\Plans\PlanGuard;
use App\Services\Prompts\PromptContext;
use App\Services\Prompts\PromptGenerator;
use App\Services\Support\ApiRetry;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Entry points for scans:
 *  - prepare() + runSync(): the scan:test command. Runs the same ScanPromptJob
 *    and FinalizeScanRunJob as the background path, in this process.
 *  - startManual(): the "Scan now" button. Queues the scan for the cron/queue.
 */
final class ScanService
{
    public function __construct(
        private readonly PromptGenerator $promptGenerator,
        private readonly EngineManager $engines,
        private readonly PlanGuard $plans,
        private readonly ScanPlanner $planner,
        private readonly ManualScanGate $manualScans,
        private readonly ScanLauncher $launcher,
        private readonly ApiRetry $retry,
        private readonly ScanInsights $insights,
    ) {}

    /**
     * Create/update the hotel and competitors, store the prompts and open a
     * queued scan run with its plan (scan:test).
     */
    public function prepare(ScanRequest $request): PreparedScan
    {
        return DB::transaction(function () use ($request) {
            $hotel = Hotel::firstOrNew(['name' => trim($request->hotelName), 'city' => trim($request->city)]);
            $hotel->country = trim($request->country);
            $hotel->hotel_type = $request->hotelType ?? $hotel->hotel_type;
            $hotel->website = $request->website ?? $hotel->website;
            $hotel->save();

            $competitorNames = $this->syncCompetitors($hotel, $request->competitors, $request->replaceCompetitors);

            $generated = $this->promptGenerator->generate(new PromptContext(
                hotelName: $hotel->name,
                city: $hotel->city,
                country: $hotel->country,
                hotelType: $hotel->hotel_type,
                language: $request->language,
            ), $request->promptCount);

            if ($generated === []) {
                throw new RuntimeException('The prompt generator produced no prompts for this hotel.');
            }

            $prompts = collect($generated)->map(function ($g) use ($hotel) {
                $prompt = Prompt::firstOrNew([
                    'hotel_id' => $hotel->id,
                    'text' => $g->text,
                    'language' => $g->language,
                ]);
                $prompt->persona = $g->persona;
                if (! $prompt->exists) {
                    $prompt->source = PromptSource::Generated;
                }
                $prompt->is_active = true;
                $prompt->save();

                return $prompt;
            });

            // The generated set is the hotel's current prompt set; retire older ones.
            $hotel->prompts()->whereNotIn('id', $prompts->pluck('id'))->update(['is_active' => false]);

            $engines = $this->plans->engines($hotel);

            $scanRun = ScanRun::create([
                'hotel_id' => $hotel->id,
                'engine' => implode(',', $engines),
                'model' => $engines === [] ? 'none' : $this->engines->make($engines[0])->model(),
                'trigger' => ScanTrigger::Cli,
                'status' => ScanStatus::Queued,
                'prompt_ids' => $prompts->pluck('id')->all(),
                'runs_per_prompt' => max(1, $request->runs),
                'cost_usd' => 0,
            ]);

            return new PreparedScan(
                hotel: $hotel,
                scanRun: $scanRun,
                prompts: $prompts,
                competitors: $competitorNames,
                runs: max(1, $request->runs),
                requestedLanguage: $request->language,
                promptLanguage: $generated[0]->language,
            );
        });
    }

    /**
     * Run a scan in this process: every planned ScanPromptJob (with the
     * Milestone 1 retry rules), then FinalizeScanRunJob, then the insight jobs.
     *
     * @param  (Closure(int $done, int $total): void)|null  $onProgress
     */
    public function runSync(ScanRun $scanRun, ?Closure $onProgress = null): ScanSummary
    {
        $this->planner->plan($scanRun);
        $calls = $this->planner->calls($scanRun);
        $scanRun->update(['status' => ScanStatus::Running, 'started_at' => now()]);

        $done = 0;
        foreach ($calls as $call) {
            $job = new ScanPromptJob($scanRun->id, $call->promptId, $call->runNumber, $call->engine);

            try {
                // dispatchNow (not dispatchSync): the sync queue would call failed() on
                // every attempt; here only the last failed attempt is recorded.
                $this->retry->run(fn () => Bus::dispatchNow($job));
            } catch (Throwable $e) {
                $job->failed($e);
            }

            Cache::forget($job->startsKey());

            if ($onProgress !== null) {
                $onProgress(++$done, count($calls));
            }
        }

        Bus::dispatchSync(new FinalizeScanRunJob($scanRun->id, runInsights: false));
        $this->insights->runNow($scanRun);

        return $this->summary($scanRun->fresh(), count($calls));
    }

    /**
     * "Scan now": apply the manual scan rules, then queue the scan.
     *
     * @throws ManualScanNotAllowed
     */
    public function startManual(Hotel $hotel, User $user): ScanRun
    {
        // Two quick clicks must not start two scans.
        $lock = Cache::lock("citevanta:manual-scan:{$hotel->id}", 10);

        if (! $lock->get()) {
            throw new ManualScanNotAllowed(new ManualScanCheck(false, 'A scan is already being started for this hotel.', 0, null));
        }

        try {
            $check = $this->manualScans->check($hotel, $user);

            if (! $check->allowed) {
                throw new ManualScanNotAllowed($check);
            }

            return $this->launcher->queue($hotel, $user->is_admin ? ScanTrigger::Admin : ScanTrigger::Manual, $user);
        } finally {
            $lock->release();
        }
    }

    private function summary(ScanRun $scanRun, int $attempted): ScanSummary
    {
        $analyzed = $scanRun->responses()->whereHas('analysis')->count();
        $costs = CostCalculator::fromConfig();
        $models = [...$scanRun->responses()->distinct()->pluck('model')->filter()->all(), (string) config('citevanta.analysis_model')];

        return new ScanSummary(
            attempted: $attempted,
            answered: $scanRun->answers_count,
            analyzed: $analyzed,
            engineFailures: max(0, $attempted - $scanRun->answers_count),
            analysisFailures: max(0, $scanRun->answers_count - $analyzed),
            costUsd: (float) $scanRun->cost_usd,
            errors: $scanRun->errors ?? [],
            unpricedModels: array_values(array_unique(array_filter($models, fn ($m) => ! $costs->hasPriceFor($m)))),
        );
    }

    /**
     * scan:test competitors: add the listed names that are missing. Existing
     * competitors are only removed (soft deleted) when $replace is true.
     *
     * @param  list<string>  $names
     * @return list<string> the hotel's active competitors after syncing
     */
    private function syncCompetitors(Hotel $hotel, array $names, bool $replace = false): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names))));

        foreach ($names as $name) {
            $competitor = Competitor::withTrashed()->firstOrCreate(['hotel_id' => $hotel->id, 'name' => $name]);
            if ($competitor->trashed()) {
                $competitor->restore();
            }
        }

        if ($replace && $names !== []) {
            $hotel->competitors()->whereNotIn('name', $names)->delete();
        }

        return $hotel->competitors()->orderBy('id')->pluck('name')->all();
    }
}
