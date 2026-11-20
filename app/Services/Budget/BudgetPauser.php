<?php

namespace App\Services\Budget;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\FinalizeAuditJob;
use App\Jobs\FinalizeScanRunJob;
use App\Jobs\RunAuditJob;
use App\Jobs\ScanHotelJob;
use App\Jobs\ScanInsightsJob;
use App\Jobs\ScanPromptJob;
use App\Models\Audit;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Scanning\PlannedCall;
use App\Services\Scanning\ScanPlanner;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Pauses work the budget refused ("paused_budget", never "failed") and
 * resumes it: the calls a paused scan or audit is still missing run again,
 * and paused insights are queued again (insight jobs skip calls already made).
 */
final class BudgetPauser
{
    private const ACTIVE = [ScanStatus::Waiting, ScanStatus::Pending, ScanStatus::Queued, ScanStatus::Running];

    public function __construct(private readonly ScanPlanner $planner) {}

    public function pauseScan(ScanRun $scanRun, string $reason): void
    {
        $scanRun->refresh();

        if (! in_array($scanRun->status, self::ACTIVE, true)) {
            return;
        }

        $scanRun->update(['status' => ScanStatus::PausedBudget, 'hold_reason' => $reason]);

        if ($scanRun->trigger === ScanTrigger::Audit) {
            Audit::query()->where('scan_run_id', $scanRun->id)->whereIn('status', [Audit::QUEUED, Audit::RUNNING])->update(['status' => Audit::PAUSED]);
        }
    }

    public function pauseInsights(ScanRun $scanRun): void
    {
        ScanRun::query()->whereKey($scanRun->id)->whereNull('insights_paused_at')->update(['insights_paused_at' => now()]);
    }

    /**
     * Billing stop: every scan and audit that has not finished pauses.
     */
    public function pauseAll(string $reason): int
    {
        // Private-plan teams use their own OpenAI key: our billing stop does not touch them.
        $runs = ScanRun::query()->whereIn('status', array_map(fn (ScanStatus $s) => $s->value, self::ACTIVE))
            ->whereNotIn('hotel_id', Hotel::query()->whereIn('team_id', Team::query()->where('plan', Plan::Private->value)->select('id'))->select('id'))
            ->get();
        $runs->each(fn (ScanRun $run) => $this->pauseScan($run, $reason));

        return $runs->count();
    }

    public function pausedCount(): int
    {
        return ScanRun::query()->where('status', ScanStatus::PausedBudget->value)->count()
            + ScanRun::query()->whereNotNull('insights_paused_at')->count();
    }

    /**
     * @return int scans and insight runs resumed
     */
    public function resumeAll(): int
    {
        $resumed = 0;

        ScanRun::query()->where('status', ScanStatus::PausedBudget->value)->orderBy('id')->each(function (ScanRun $scanRun) use (&$resumed): void {
            $this->resumeScan($scanRun);
            $resumed++;
        });

        ScanRun::query()->whereNotNull('insights_paused_at')->orderBy('id')->each(function (ScanRun $scanRun) use (&$resumed): void {
            $scanRun->update(['insights_paused_at' => null]);
            ScanInsightsJob::dispatch($scanRun->id);
            $resumed++;
        });

        return $resumed;
    }

    public function resumeScan(ScanRun $scanRun): void
    {
        $isAudit = $scanRun->trigger === ScanTrigger::Audit;
        $audit = $isAudit ? Audit::query()->where('scan_run_id', $scanRun->id)->first() : null;

        // Paused before it started: start it the normal way.
        if ($scanRun->prompt_ids === null || $scanRun->batch_id === null) {
            $scanRun->update(['status' => ScanStatus::Queued, 'hold_reason' => null, 'batch_id' => null]);
            $audit?->update(['status' => Audit::QUEUED]);
            $isAudit ? RunAuditJob::dispatch($audit->id) : ScanHotelJob::dispatch($scanRun);

            return;
        }

        $scanRun->update(['status' => ScanStatus::Running, 'hold_reason' => null]);
        $audit?->update(['status' => Audit::RUNNING]);

        $queue = $isAudit ? RunAuditJob::QUEUE : 'scans';
        $scanRunId = $scanRun->id;
        $auditId = $audit?->id;
        $finalize = function () use ($scanRunId, $auditId): void {
            $auditId !== null ? FinalizeAuditJob::dispatch($auditId) : FinalizeScanRunJob::dispatch($scanRunId);
        };

        $missing = $this->planner->missingCalls($scanRun);

        if ($missing === []) {
            $finalize();

            return;
        }

        $jobs = array_map(function (PlannedCall $call) use ($scanRunId, $queue) {
            $job = (new ScanPromptJob($scanRunId, $call->promptId, $call->runNumber, $call->engine))->onQueue($queue);
            Cache::forget($job->startsKey());

            return $job;
        }, $missing);

        $batch = Bus::batch($jobs)
            ->name("Scan #{$scanRunId} resumed")
            ->onQueue($queue)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanRunId, $auditId): void {
                $auditId !== null ? FinalizeAuditJob::dispatch($auditId) : FinalizeScanRunJob::dispatch($scanRunId);
            })
            ->dispatch();

        $scanRun->update(['batch_id' => $batch->id]);
    }
}
