<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\ScanRun;
use App\Services\Scanning\ScanFinalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stores a scan's results once its calls are done (see ScanFinalizer), then
 * queues the insight jobs (ScanInsightsJob) for a scan with results.
 */
class FinalizeScanRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * @param  bool  $runInsights  false when the caller runs the insights itself (ScanService::runSync)
     */
    public function __construct(public int $scanRunId, public bool $runInsights = true)
    {
        $this->onQueue('scans');
    }

    public function handle(ScanFinalizer $finalizer): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        // Paused for budget: finalized after it resumes and its missing calls ran.
        if ($scanRun === null || $scanRun->status === ScanStatus::PausedBudget) {
            return;
        }

        $scanRun = $finalizer->finalize($scanRun);

        if ($this->runInsights && in_array($scanRun->status->value, ScanStatus::finishedWithResults(), true)) {
            ScanInsightsJob::dispatch($scanRun->id);
        }
    }
}
