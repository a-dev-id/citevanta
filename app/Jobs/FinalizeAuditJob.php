<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Audit;
use App\Services\Audits\AuditReportBuilder;
use App\Services\Audits\AuditService;
use App\Services\Scanning\ScanFinalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes a free audit: stores the scan's results (no insights: no accuracy
 * checks, competitor reasons or recommendations), builds the report, records
 * the cost and emails the report link. Makes no AI call.
 */
class FinalizeAuditJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $auditId)
    {
        $this->onQueue(RunAuditJob::QUEUE);
    }

    public function handle(ScanFinalizer $finalizer, AuditReportBuilder $reports, AuditService $audits): void
    {
        $audit = Audit::with('scanRun.hotel')->find($this->auditId);

        if ($audit === null || $audit->scanRun === null || in_array($audit->status, [Audit::COMPLETED, Audit::FAILED, Audit::PAUSED], true)
            || $audit->scanRun->status === ScanStatus::PausedBudget) {
            return;
        }

        $scan = $finalizer->finalize($audit->scanRun);
        $audit->setRelation('scanRun', $scan);

        $audits->complete($audit, $reports->build($audit, $scan));
    }
}
