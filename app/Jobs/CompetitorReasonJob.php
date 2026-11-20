<?php

namespace App\Jobs;

use App\Enums\AiPurpose;
use App\Models\Prompt;
use App\Models\ScanRun;
use App\Services\Budget\BudgetExceeded;
use App\Services\Engines\EngineManager;
use App\Services\Insights\Competitors\CompetitorReasoner;
use App\Services\Insights\Competitors\CompetitorReasonService;
use App\Services\Insights\InsightCalls;
use App\Services\Support\ApiRetry;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Exactly one AI call: why competitors were recommended instead of the
 * hotel for one prompt of a scan (CompetitorReasonService).
 *
 * Idempotent: the call (ai_calls) and the insight are stored together, and a
 * job whose call is already stored does nothing.
 */
class CompetitorReasonJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $timeout;

    /**
     * @param  array{prompt_id: int, wins: int, competitors: list<string>, mentions: int}  $candidate
     */
    public function __construct(public int $scanRunId, public array $candidate)
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

    public function handle(CompetitorReasoner $reasoner, CompetitorReasonService $service, InsightCalls $calls): void
    {
        $scanRun = ScanRun::with('hotel')->find($this->scanRunId);
        $prompt = Prompt::withTrashed()->find($this->candidate['prompt_id']);

        if (! config('citevanta.insights.competitor_reasons.enabled') || $scanRun === null || $prompt === null
            || $calls->done($scanRun, AiPurpose::CompetitorReasons, $this->subject())) {
            return;
        }

        try {
            $result = $calls->guarded($scanRun, fn () => $reasoner->explain($service->inputFor($scanRun, $prompt, $this->candidate['competitors'])));
        } catch (BudgetExceeded) {
            $calls->pause($scanRun);

            return;
        } catch (Throwable $e) {
            if (! ApiRetry::isRetryable($e)) {
                $calls->recordFailure($scanRun, AiPurpose::CompetitorReasons, $this->subject(), $e);

                return;
            }

            throw $e;
        }

        DB::transaction(function () use ($scanRun, $result, $service, $calls): void {
            $calls->record($scanRun, AiPurpose::CompetitorReasons, $this->subject(), $result->model, $result->tokensIn, $result->tokensOut, [
                'summary' => $result->summary,
                'reasons' => array_map(fn ($reason) => $reason->toArray(), $result->reasons),
            ]);
            $service->apply($scanRun, $this->candidate, $result);
        });

        $calls->refreshCost($scanRun);
    }

    public function failed(?Throwable $exception): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun !== null) {
            app(InsightCalls::class)->recordFailure($scanRun, AiPurpose::CompetitorReasons, $this->subject(), $exception ?? new RuntimeException('Failed after retries.'));
        }
    }

    public function subject(): string
    {
        return 'prompt:'.$this->candidate['prompt_id'];
    }
}
