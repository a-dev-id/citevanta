<?php

namespace App\Services\Scanning;

use App\Enums\AiCallKind;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\AiSpend;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetGuard;
use App\Services\Budget\TeamCostCap;
use App\Services\Plans\PlanGuard;
use Carbon\CarbonInterface;

/**
 * Rules for the "Scan now" button:
 *  1. no scan already queued or running for the hotel (everyone, admins included)
 *  2. the plan allows scanning (e.g. trial not expired)
 *  3. the team's manual_scans_per_month allowance, which resets on the 1st
 *     of each month in the team's timezone
 *  4. at most one manual scan per hotel every manual_scan_cooldown_hours
 *
 * Admin users skip rules 2-4. Scans they start are recorded as
 * ScanTrigger::Admin and never count toward the team's allowance.
 */
final class ManualScanGate
{
    public function __construct(
        private readonly PlanGuard $plans,
        private readonly ScanSchedule $schedule,
    ) {}

    public function check(Hotel $hotel, User $user): ManualScanCheck
    {
        $team = $hotel->team;
        $isAdmin = $user->is_admin;
        $limit = $isAdmin || $team === null ? null : $team->limits()->manualScansPerMonth;
        $used = $team === null ? 0 : $this->usedThisMonth($hotel);
        $nextScheduledAt = $this->schedule->nextScheduledAt($hotel);

        $deny = fn (string $reason, $availableAt = null) => new ManualScanCheck(
            allowed: false,
            reason: $reason,
            used: $used,
            limit: $limit,
            availableAt: $availableAt,
            nextScheduledAt: $nextScheduledAt,
        );

        if ($hotel->scanRuns()->whereIn('status', ScanStatus::inProgress())->exists()) {
            return $deny('A scan is already running for this hotel.');
        }

        if (! $isAdmin && ! $this->plans->canScan($hotel)) {
            return $deny(match (true) {
                (bool) $team?->trialHasExpired() => 'Your trial has ended. Choose a plan to keep scanning.',
                (bool) $team?->isReadOnly() => 'Your subscription has expired. Renew it on Settings > Billing to scan again.',
                default => 'Your plan does not include scans.',
            });
        }

        if (! $isAdmin && $team !== null && ($budgetReason = $this->budgetReason($hotel)) !== null) {
            return $deny($budgetReason);
        }

        if ($limit !== null && $used >= $limit) {
            return $deny(
                "Manual scans used ({$used} of {$limit}). Next scheduled scan: "
                    .($nextScheduledAt !== null ? $this->format($nextScheduledAt, $hotel) : 'not scheduled').'.',
                $team->nextPeriodStart(),
            );
        }

        $cooldownEndsAt = $isAdmin ? null : $this->cooldownEndsAt($hotel);
        if ($cooldownEndsAt !== null && $cooldownEndsAt->isFuture()) {
            return $deny(
                'One manual scan per hotel every '.$this->cooldownHours().' hours. Next manual scan: '
                    .$this->format($cooldownEndsAt, $hotel, withTime: true).'.',
                $cooldownEndsAt,
            );
        }

        return new ManualScanCheck(
            allowed: true,
            reason: null,
            used: $used,
            limit: $limit,
            nextScheduledAt: $nextScheduledAt,
        );
    }

    /**
     * Manual scans (by non-admins) across all of the team's hotels since the
     * start of the current month in the team's timezone. Failed scans count:
     * they still cost API calls.
     */
    public function usedThisMonth(Hotel $hotel): int
    {
        return ScanRun::query()
            ->whereIn('hotel_id', $hotel->team->hotels()->select('id'))
            ->where('trigger', ScanTrigger::Manual)
            ->where('created_at', '>=', $hotel->team->currentPeriodStart())
            ->count();
    }

    /**
     * The estimated cost of a manual scan of this hotel.
     */
    public function estimate(Hotel $hotel): float
    {
        $calls = $hotel->prompts()->where('is_active', true)->count()
            * $this->plans->runsPerPrompt($hotel)
            * max(1, count($this->plans->engines($hotel)));

        return app(AiSpend::class)->estimateCalls($calls);
    }

    /**
     * Why the AI budget refuses a manual scan now, or null.
     */
    private function budgetReason(Hotel $hotel): ?string
    {
        if (app(TeamCostCap::class)->exceeded($hotel->team, $this->estimate($hotel))) {
            return TeamCostCap::MESSAGE;
        }

        try {
            app(BudgetGuard::class)->authorize(AiCallContext::forTeam($hotel->team, $hotel->id), AiCallKind::Answer);
        } catch (BudgetExceeded $e) {
            return in_array($e->reason, [BudgetExceeded::TEAM_KEY, BudgetExceeded::TEAM_BUDGET], true)
                ? $e->getMessage()
                : 'Manual scans are paused for a while because of high demand. Your scheduled scans keep running.';
        }

        return null;
    }

    private function cooldownEndsAt(Hotel $hotel): ?CarbonInterface
    {
        $last = $hotel->scanRuns()
            ->whereIn('trigger', [ScanTrigger::Manual, ScanTrigger::Admin])
            ->latest('created_at')
            ->value('created_at');

        return $last !== null ? now()->parse($last)->addHours($this->cooldownHours()) : null;
    }

    private function cooldownHours(): int
    {
        return (int) config('citevanta.manual_scan_cooldown_hours', 24);
    }

    private function format(CarbonInterface $date, Hotel $hotel, bool $withTime = false): string
    {
        $timezone = $hotel->team?->timezoneName() ?? (string) config('citevanta.default_timezone');

        return $date->copy()->setTimezone($timezone)->format($withTime ? 'j M Y, H:i' : 'j M Y');
    }
}
