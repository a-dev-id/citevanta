<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\ScanRun;
use App\Services\Scanning\PlannedCall;
use App\Services\Scanning\ScanFinalizer;
use App\Services\Scanning\ScanPlanner;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/**
 * Starts a queued scan: plans its calls and dispatches one ScanPromptJob per
 * prompt x run x engine as a batch. When the batch is done (with or without
 * failures) FinalizeScanRunJob stores the results.
 */
class ScanHotelJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds the per-hotel unique lock lasts if the job never runs. */
    public int $uniqueFor = 3600;

    public int $scanRunId;

    public int $hotelId;

    public function __construct(ScanRun $scanRun)
    {
        $this->scanRunId = $scanRun->id;
        $this->hotelId = $scanRun->hotel_id;
        $this->onQueue('scans');
    }

    /**
     * One ScanHotelJob per hotel at a time.
     */
    public function uniqueId(): string
    {
        return 'hotel-'.$this->hotelId;
    }

    public function handle(ScanPlanner $planner, ?ScanFinalizer $finalizer = null): void
    {
        $finalizer ??= app(ScanFinalizer::class);

        $scanRun = ScanRun::find($this->scanRunId);

        if ($scanRun === null || ! in_array($scanRun->status, [ScanStatus::Queued, ScanStatus::Pending], true) || $scanRun->batch_id !== null) {
            return;
        }

        $planner->plan($scanRun);
        $calls = $planner->calls($scanRun);

        $scanRun->update(['status' => ScanStatus::Running, 'started_at' => now()]);

        if ($calls === []) {
            // No active prompts or no usable engine: close the scan as failed.
            $finalizer->finalize($scanRun);

            return;
        }

        $scanRunId = $scanRun->id;

        $batch = Bus::batch(array_map(
            fn (PlannedCall $call) => new ScanPromptJob($scanRunId, $call->promptId, $call->runNumber, $call->engine),
            $calls,
        ))
            ->name("Scan #{$scanRunId}")
            ->onQueue('scans')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanRunId): void {
                FinalizeScanRunJob::dispatch($scanRunId);
            })
            ->dispatch();

        $scanRun->update(['batch_id' => $batch->id]);
    }
}
