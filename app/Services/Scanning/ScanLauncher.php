<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Plans\PlanGuard;
use App\Services\Trials\TrialGate;

/**
 * Creates a queued scan run and dispatches ScanHotelJob for it. Used by the
 * setup wizard, "Scan now" and the daily scheduler. A trial scan that may
 * not start yet (TrialGate) is created as "waiting" and not dispatched.
 */
final class ScanLauncher
{
    public function __construct(
        private readonly PlanGuard $plans,
        private readonly TrialGate $trials,
    ) {}

    public function queue(Hotel $hotel, ScanTrigger $trigger, ?User $user = null): ScanRun
    {
        $holdReason = $this->trials->holdReason($hotel, $trigger);

        $scanRun = $hotel->scanRuns()->create([
            'engine' => implode(',', $this->plans->engines($hotel)),
            'model' => 'pending',
            'trigger' => $trigger,
            'triggered_by' => $user?->id,
            'status' => $holdReason === null ? ScanStatus::Queued : ScanStatus::Waiting,
            'hold_reason' => $holdReason,
            'cost_usd' => 0,
        ]);

        if ($holdReason !== null) {
            return $scanRun;
        }

        ScanHotelJob::dispatch($scanRun)->afterCommit();

        return $scanRun;
    }
}
