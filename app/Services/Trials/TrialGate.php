<?php

namespace App\Services\Trials;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Jobs\ScanInsightsJob;
use App\Mail\TrialApproved;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Budget\BudgetPauser;
use App\Services\Plans\PlanGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Whether a trial team's scan may start. A scan that may not is created as
 * "waiting" with a hold reason and starts on its own once the reason is gone
 * (releaseWaiting runs every 5 minutes, and right after email verification
 * or an admin approval).
 *
 *  - verification: no owner of the team has verified their email
 *  - approval: TRIAL_REQUIRES_APPROVAL was on when the trial started and no admin approved it yet
 *
 * The trial's AI cost is capped separately by the "trials" budget bucket (BudgetGuard).
 */
final class TrialGate
{
    public const VERIFICATION = 'verification';

    public const APPROVAL = 'approval';

    public function holdReason(Hotel $hotel, ScanTrigger $trigger): ?string
    {
        $team = $hotel->team;

        if ($team === null || ! $team->isOnTrial() || ! in_array($trigger, [ScanTrigger::Setup, ScanTrigger::Scheduled], true)) {
            return null;
        }

        if (! $team->owners()->whereNotNull('email_verified_at')->exists()) {
            return self::VERIFICATION;
        }

        return $team->awaitsTrialApproval() ? self::APPROVAL : null;
    }

    /**
     * Start every waiting scan whose reason is gone.
     *
     * @return int scans started
     */
    public function releaseWaiting(?Team $team = null): int
    {
        $released = 0;

        ScanRun::query()->where('status', ScanStatus::Waiting->value)
            ->when($team !== null, fn ($query) => $query->whereIn('hotel_id', $team->hotels()->select('id')))
            ->with('hotel.team')
            ->orderBy('id')
            ->each(function (ScanRun $scanRun) use (&$released): void {
                $reason = $scanRun->hotel === null ? null : $this->holdReason($scanRun->hotel, $scanRun->trigger);

                if ($reason !== null) {
                    if ($scanRun->hold_reason !== $reason) {
                        $scanRun->update(['hold_reason' => $reason]);
                    }

                    return;
                }

                $scanRun->update([
                    'status' => ScanStatus::Queued,
                    'hold_reason' => null,
                    // The plan may have changed while it waited (e.g. paid).
                    'engine' => implode(',', app(PlanGuard::class)->engines($scanRun->hotel)),
                    'created_at' => now(),
                ]);
                ScanHotelJob::dispatch($scanRun)->afterCommit();
                $released++;
            });

        return $released;
    }

    /**
     * Admin > Teams "Approve": the trial may use AI. The owners are emailed,
     * and a waiting setup scan or paused insights (audit import) start.
     */
    public function approveTrial(Team $team): void
    {
        if ($team->trial_approved_at !== null) {
            return;
        }

        $team->forceFill(['trial_approved_at' => now()])->save();
        $team->owners()->get()->each(fn (User $owner) => Mail::to($owner)->queue(new TrialApproved($team)));
        $this->releaseWaiting($team);

        $pauser = app(BudgetPauser::class);
        ScanRun::query()->whereIn('hotel_id', $team->hotels()->select('id'))
            ->where(fn ($query) => $query->whereNotNull('insights_paused_at')->orWhere('status', ScanStatus::PausedBudget->value))
            ->each(function (ScanRun $scanRun) use ($pauser): void {
                if ($scanRun->status === ScanStatus::PausedBudget) {
                    $pauser->resumeScan($scanRun);
                } else {
                    $scanRun->update(['insights_paused_at' => null]);
                    ScanInsightsJob::dispatch($scanRun->id);
                }
            });
    }

    /**
     * Trial teams waiting for approval (Admin > Teams).
     *
     * @return Collection<int, Team>
     */
    public function awaitingApproval()
    {
        return Team::query()->where('plan', 'trial')->where('trial_approval_required', true)->whereNull('trial_approved_at')->orderBy('id')->get();
    }

    public static function message(?string $reason): string
    {
        return match ($reason) {
            self::VERIFICATION => 'Your first scan starts as soon as you verify your email address. Check your inbox for the link.',
            self::APPROVAL => 'We are preparing your account. Your first scan starts as soon as it is ready, and we will email you.',
            default => 'Your scan is waiting to start.',
        };
    }
}
