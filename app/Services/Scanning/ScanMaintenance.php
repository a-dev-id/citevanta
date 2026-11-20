<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\FinalizeScanRunJob;
use App\Jobs\ScanHotelJob;
use App\Jobs\ScanPromptJob;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Services\Plans\PlanGuard;
use App\Services\Trials\TrialGate;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The scheduled scan tasks and the admin "Retry failed calls".
 */
final class ScanMaintenance
{
    public function __construct(
        private readonly ScanLauncher $launcher,
        private readonly ScanSchedule $schedule,
        private readonly ScanPlanner $planner,
        private readonly ScanFinalizer $finalizer,
        private readonly PlanGuard $plans,
        private readonly TrialGate $trials,
    ) {}

    /**
     * Safety net: scans queued for a while without a batch (their
     * ScanHotelJob was lost or never dispatched) are dispatched again.
     * ScanHotelJob is unique per hotel, so this never doubles a scan.
     *
     * @return int scans dispatched
     */
    public function dispatchQueued(): int
    {
        // Trial scans whose hold is over (verified, approved, budget room).
        $released = $this->trials->releaseWaiting();

        $runs = ScanRun::query()
            ->where('status', ScanStatus::Queued)
            ->whereNull('batch_id')
            ->where('created_at', '<=', now()->subMinutes((int) config('citevanta.queued_dispatch_after_minutes', 5)))
            ->get();

        $runs->each(fn (ScanRun $run) => ScanHotelJob::dispatch($run));

        return $runs->count() + $released;
    }

    /**
     * Queue scheduled scans for set-up hotels whose last completed scan is
     * older than 30 / scans_per_month days. Skips expired trials and hotels
     * with a scan already queued or running.
     *
     * @return int scans queued
     */
    public function dispatchDue(): int
    {
        $queued = 0;

        Hotel::query()
            ->whereNotNull('setup_completed_at')
            ->whereNotNull('team_id')
            ->with('team')
            ->whereDoesntHave('scanRuns', fn ($query) => $query->whereIn('status', ScanStatus::inProgress()))
            ->orderBy('id')
            ->each(function (Hotel $hotel) use (&$queued): void {
                if (! $this->plans->canScan($hotel) || ! $this->schedule->isDue($hotel)) {
                    return;
                }

                $this->launcher->queue($hotel, ScanTrigger::Scheduled);
                $queued++;
            });

        return $queued;
    }

    /**
     * A running scan with no new answer for stuck_after_minutes is closed with
     * what it collected: completed_with_errors if it has answers, else failed.
     *
     * @return int scans closed
     */
    public function failStuck(): int
    {
        $cutoff = now()->subMinutes((int) config('citevanta.stuck_after_minutes', 90));
        $closed = 0;

        ScanRun::query()
            ->where('status', ScanStatus::Running)
            ->where('started_at', '<=', $cutoff)
            ->whereDoesntHave('responses', fn ($query) => $query->where('created_at', '>', $cutoff))
            ->each(function (ScanRun $scanRun) use (&$closed): void {
                if ($scanRun->batch_id !== null) {
                    Bus::findBatch($scanRun->batch_id)?->cancel();
                }

                $this->finalizer->finalize($scanRun);
                $closed++;
            });

        return $closed;
    }

    /**
     * Re-dispatch only the calls that have no analysed answer, then finalize
     * again. Returns how many calls were queued.
     *
     * @throws RuntimeException when the scan is still in progress
     */
    public function retryMissing(ScanRun $scanRun): int
    {
        if ($scanRun->status->isInProgress()) {
            throw new RuntimeException('This scan is still in progress.');
        }

        $missing = $this->planner->missingCalls($scanRun);

        if ($missing === []) {
            return 0;
        }

        $jobs = array_map(function (PlannedCall $call) use ($scanRun) {
            $job = new ScanPromptJob($scanRun->id, $call->promptId, $call->runNumber, $call->engine);
            Cache::forget($job->startsKey());

            return $job;
        }, $missing);

        $scanRun->update([
            'status' => ScanStatus::Running,
            'finished_at' => null,
            'failed_count' => 0,
            'errors' => [],
        ]);

        $scanRunId = $scanRun->id;

        $batch = Bus::batch($jobs)
            ->name("Scan #{$scanRunId} retry")
            ->onQueue('scans')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanRunId): void {
                FinalizeScanRunJob::dispatch($scanRunId);
            })
            ->dispatch();

        $scanRun->update(['batch_id' => $batch->id]);

        return count($jobs);
    }
}
