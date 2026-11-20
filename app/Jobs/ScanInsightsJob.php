<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Services\Insights\ScanInsights;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * After a scan is finalised: dispatches the insight jobs (accuracy checks,
 * competitor reasons) as a batch; FinalizeInsightsJob runs when they are done.
 * Makes no AI call itself.
 */
class ScanInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $scanRunId)
    {
        $this->onQueue('scans');
    }

    public function handle(ScanInsights $insights): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun === null) {
            return;
        }

        $jobs = $insights->jobs($scanRun);
        $scanRunId = $scanRun->id;

        if ($jobs === []) {
            FinalizeInsightsJob::dispatch($scanRunId);

            return;
        }

        Bus::batch($jobs)
            ->name("Insights for scan #{$scanRunId}")
            ->onQueue('scans')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanRunId): void {
                FinalizeInsightsJob::dispatch($scanRunId);
            })
            ->dispatch();
    }
}
