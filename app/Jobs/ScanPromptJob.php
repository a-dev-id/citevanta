<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Prompt;
use App\Models\ScanRun;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetPauser;
use App\Services\Engines\EngineManager;
use App\Services\Scanning\ScanCallRunner;
use App\Services\Support\ApiRetry;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Exactly one API call: ask one engine one prompt once, save the answer and
 * its citations, analyse it.
 *
 * Retries (Milestone 1 rules): an exception is retried after 2s, then 6s, and
 * fails on the 3rd (maxExceptions). Auth/bad-request errors are recorded at
 * once without retrying. A job cut off by a time limit never reaches its
 * "finally", so its start counter stays raised; on the 4th start it is
 * recorded as "timed out 3 times" and stops. $tries is higher than 3 only to
 * leave room for rate-limit releases (they count as attempts); it still
 * guarantees the job can never retry forever.
 *
 * A failed call is recorded on the scan run and never fails the batch.
 */
class ScanPromptJob implements ShouldQueue
{
    use Batchable, Queueable;

    public const MAX_STARTS = 3;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [2, 6];

    public int $timeout;

    public bool $failOnTimeout = false;

    public function __construct(
        public int $scanRunId,
        public int $promptId,
        public int $runNumber,
        public string $engine,
    ) {
        $this->timeout = (int) config('citevanta.job_timeout', 70);
        $this->onQueue('scans');
    }

    /**
     * Per-engine rate limit, only when running on a real queue with a real engine.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        if ($this->job === null || app(EngineManager::class)->isFake()) {
            return [];
        }

        return [new RateLimited('citevanta-engine')];
    }

    public function handle(ScanCallRunner $runner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $scanRun = ScanRun::find($this->scanRunId);
        $prompt = Prompt::withTrashed()->find($this->promptId);

        // Paused (budget) or stopped (runaway guard): make no call.
        if ($scanRun === null || $prompt === null || in_array($scanRun->status, [ScanStatus::PausedBudget, ScanStatus::Failed], true)) {
            return;
        }

        $startsKey = $this->startsKey();
        Cache::add($startsKey, 0, now()->addDay());

        if (Cache::increment($startsKey) > self::MAX_STARTS) {
            Cache::forget($startsKey);
            $runner->recordFailure($scanRun, $this->label(), new RuntimeException('Timed out '.self::MAX_STARTS.' times.'));

            return;
        }

        try {
            $runner->run($scanRun, $prompt, $this->runNumber, $this->engine);
        } catch (BudgetExceeded $e) {
            // Not a failure: the scan waits for budget (Admin > System resumes it).
            if ($e->reason !== BudgetExceeded::RUNAWAY) {
                app(BudgetPauser::class)->pauseScan($scanRun, $e->reason);
            }

            return;
        } catch (Throwable $e) {
            if (! ApiRetry::isRetryable($e)) {
                $runner->recordFailure($scanRun, $this->label(), $e);

                return;
            }

            throw $e;
        } finally {
            // Reached on success or exception (not when cut off): undo this start.
            Cache::decrement($startsKey);
        }
    }

    /**
     * Called by the queue after the last attempt. Records the call as failed.
     */
    public function failed(?Throwable $exception): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun !== null) {
            app(ScanCallRunner::class)->recordFailure(
                $scanRun,
                $this->label(),
                $exception ?? new RuntimeException('Failed after retries.'),
            );
        }
    }

    public function label(): string
    {
        return "Prompt #{$this->promptId} run {$this->runNumber} ({$this->engine})";
    }

    public function startsKey(): string
    {
        return "citevanta:scan-call-starts:{$this->scanRunId}:{$this->promptId}:{$this->runNumber}:{$this->engine}";
    }
}
