<?php

namespace App\Jobs;

use App\Enums\AiPurpose;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Budget\BudgetExceeded;
use App\Services\Engines\EngineManager;
use App\Services\Insights\Accuracy\AccuracyChecker;
use App\Services\Insights\Accuracy\AccuracyService;
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
 * Exactly one AI call: check one answer that mentions the hotel against the
 * hotel's facts and store what it gets wrong (AccuracyService).
 *
 * Idempotent: the call is stored in ai_calls with the issues in one
 * transaction, and a job whose call is already stored does nothing.
 */
class AccuracyCheckJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $timeout;

    public function __construct(public int $scanRunId, public int $responseId)
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

    public function handle(AccuracyChecker $checker, AccuracyService $accuracy, InsightCalls $calls): void
    {
        $scanRun = ScanRun::find($this->scanRunId);
        $response = ScanResponse::with('scanRun.hotel')->find($this->responseId);

        if (! config('citevanta.insights.accuracy.enabled') || $scanRun === null || $response === null
            || $calls->done($scanRun, AiPurpose::Accuracy, $this->subject())) {
            return;
        }

        try {
            $result = $calls->guarded($scanRun, fn () => $checker->check($accuracy->inputFor($response)));
        } catch (BudgetExceeded) {
            $calls->pause($scanRun);

            return;
        } catch (Throwable $e) {
            if (! ApiRetry::isRetryable($e)) {
                $calls->recordFailure($scanRun, AiPurpose::Accuracy, $this->subject(), $e);

                return;
            }

            throw $e;
        }

        DB::transaction(function () use ($scanRun, $response, $result, $accuracy, $calls): void {
            $calls->record($scanRun, AiPurpose::Accuracy, $this->subject(), $result->model, $result->tokensIn, $result->tokensOut, [
                'claims' => array_map(fn ($claim) => $claim->toArray(), $result->claims),
            ]);
            $accuracy->apply($response, $result);
        });

        $calls->refreshCost($scanRun);
    }

    public function failed(?Throwable $exception): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun !== null) {
            app(InsightCalls::class)->recordFailure($scanRun, AiPurpose::Accuracy, $this->subject(), $exception ?? new RuntimeException('Failed after retries.'));
        }
    }

    public function subject(): string
    {
        return 'response:'.$this->responseId;
    }
}
