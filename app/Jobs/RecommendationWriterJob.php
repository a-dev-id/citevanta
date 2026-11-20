<?php

namespace App\Jobs;

use App\Enums\AiPurpose;
use App\Models\ScanRun;
use App\Services\Budget\BudgetExceeded;
use App\Services\Content\FactGuard;
use App\Services\Engines\EngineManager;
use App\Services\Insights\Accuracy\AccuracyService;
use App\Services\Insights\InsightCalls;
use App\Services\Recommendations\RecommendationService;
use App\Services\Recommendations\RecommendationWriter;
use App\Services\Support\ApiRetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Exactly one AI call per scan: rewrites the hotel's open recommendations that
 * still have rule text (RecommendationWriter). The rule text stays if the
 * call fails or is switched off. Numbers that are not in the facts or the
 * rule text are flagged by FactGuard.
 */
class RecommendationWriterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $timeout;

    public function __construct(public int $scanRunId)
    {
        $this->timeout = (int) config('citevanta.job_timeout', 70);
        $this->onQueue('scans');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        if ($this->job === null || app(EngineManager::class)->isFake()) {
            return [];
        }

        return [new RateLimited('citevanta-engine')];
    }

    public function handle(RecommendationWriter $writer, RecommendationService $recommendations, AccuracyService $accuracy, InsightCalls $calls): void
    {
        $scanRun = ScanRun::with('hotel')->find($this->scanRunId);

        if (! config('citevanta.recommendations.ai_writing') || $scanRun === null
            || $calls->done($scanRun, AiPurpose::Recommendations, $this->subject())) {
            return;
        }

        $hotel = $scanRun->hotel;
        $pending = $recommendations->toRewrite($hotel, (int) config('citevanta.recommendations.rewrite_per_scan', 12));

        if ($pending->isEmpty()) {
            return;
        }

        $facts = $accuracy->factsFor($hotel);
        $items = $pending->map(fn ($r) => ['key' => $r->key, 'title' => $r->title, 'why' => $r->why, 'steps' => $r->steps, 'where' => $r->where])->values()->all();

        try {
            $result = $calls->guarded($scanRun, fn () => $writer->write($hotel->name, (string) $hotel->city, $facts, $items));
        } catch (BudgetExceeded) {
            $calls->pause($scanRun);

            return;
        } catch (Throwable $e) {
            if (! ApiRetry::isRetryable($e)) {
                $calls->recordFailure($scanRun, AiPurpose::Recommendations, $this->subject(), $e);

                return;
            }

            throw $e;
        }

        DB::transaction(function () use ($scanRun, $pending, $result, $facts, $hotel, $calls): void {
            $calls->record($scanRun, AiPurpose::Recommendations, $this->subject(), $result->model, $result->tokensIn, $result->tokensOut, [
                'keys' => array_keys($result->items),
            ]);

            foreach ($pending as $recommendation) {
                $written = $result->items[$recommendation->key] ?? null;
                $fresh = $recommendation->fresh();

                // Skip if missing, or if the rule text changed since it was sent.
                if ($written === null || $fresh === null || $fresh->draft_hash !== $recommendation->draft_hash) {
                    continue;
                }

                $sources = [...array_values($facts), $hotel->name, (string) $hotel->city, $recommendation->title, $recommendation->why, implode("\n", $recommendation->steps), $recommendation->where];
                $fresh->update([
                    'title' => FactGuard::check($written['title'], $sources),
                    'why' => FactGuard::check($written['why'], $sources),
                    'steps' => array_map(fn ($step) => FactGuard::check($step, $sources), $written['steps']),
                    'where' => $written['where'] !== '' ? $written['where'] : $recommendation->where,
                    'ai_written' => true,
                ]);
            }
        });

        $calls->refreshCost($scanRun);
    }

    public function failed(?Throwable $exception): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun !== null) {
            app(InsightCalls::class)->recordFailure($scanRun, AiPurpose::Recommendations, $this->subject(), $exception ?? new RuntimeException('Failed after retries.'));
        }
    }

    public function subject(): string
    {
        return 'scan:'.$this->scanRunId;
    }
}
