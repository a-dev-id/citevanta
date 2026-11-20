<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Audit;
use App\Services\Scanning\PlannedCall;
use App\Services\Scanning\ScanPlanner;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Starts a free audit: dispatches one ScanPromptJob per prompt (10 prompts,
 * 1 run, ChatGPT) as a batch on the "audits" queue, which the cron works
 * through before "scans". FinalizeAuditJob runs when the batch is done.
 * Makes no AI call itself.
 */
class RunAuditJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'audits';

    public int $tries = 3;

    public function __construct(public int $auditId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ScanPlanner $planner): void
    {
        $audit = Audit::with('scanRun')->find($this->auditId);
        $scan = $audit?->scanRun;

        if ($audit === null || $scan === null || $audit->status !== Audit::QUEUED || $scan->batch_id !== null) {
            return;
        }

        $calls = $planner->calls($scan);
        $scan->update(['status' => ScanStatus::Running, 'started_at' => now()]);
        $audit->update(['status' => Audit::RUNNING]);
        $auditId = $audit->id;

        if ($calls === []) {
            FinalizeAuditJob::dispatch($auditId);

            return;
        }

        $batch = Bus::batch(array_map(
            fn (PlannedCall $call) => (new ScanPromptJob($scan->id, $call->promptId, $call->runNumber, $call->engine))->onQueue(self::QUEUE),
            $calls,
        ))
            ->name("Audit #{$auditId}")
            ->onQueue(self::QUEUE)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($auditId): void {
                FinalizeAuditJob::dispatch($auditId);
            })
            ->dispatch();

        $scan->update(['batch_id' => $batch->id]);
    }
}
