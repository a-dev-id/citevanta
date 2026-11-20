<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Services\Insights\ScanInsights;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wraps up a scan's insights once their jobs are done (ScanInsights::finish).
 */
class FinalizeInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $scanRunId)
    {
        $this->onQueue('scans');
    }

    public function handle(ScanInsights $insights): void
    {
        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun !== null) {
            $insights->finish($scanRun);
        }
    }
}
