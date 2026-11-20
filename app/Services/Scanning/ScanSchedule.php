<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;
use App\Models\Hotel;
use Carbon\CarbonInterface;

/**
 * When a hotel's next scheduled scan is due.
 *
 * A hotel is due when its last completed scan finished at least
 * 30 / scans_per_month days ago (e.g. every 15 days on 2 scans per month).
 * A set-up hotel that has never completed a scan is due immediately.
 */
final class ScanSchedule
{
    public function lastCompletedAt(Hotel $hotel): ?CarbonInterface
    {
        $finishedAt = $hotel->scanRuns()
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->max('finished_at');

        return $finishedAt !== null ? now()->parse($finishedAt) : null;
    }

    /**
     * Null when the hotel cannot be scanned on a schedule (no team / plan without scans).
     */
    public function nextScheduledAt(Hotel $hotel): ?CarbonInterface
    {
        $team = $hotel->team;

        if ($team === null || $team->limits()->scansPerMonth < 1) {
            return null;
        }

        $last = $this->lastCompletedAt($hotel);

        if ($last === null) {
            return now();
        }

        $intervalHours = (int) round($team->limits()->scanIntervalDays() * 24);

        return $last->copy()->addHours($intervalHours);
    }

    public function isDue(Hotel $hotel, ?CarbonInterface $at = null): bool
    {
        $next = $this->nextScheduledAt($hotel);

        return $next !== null && $next->lessThanOrEqualTo($at ?? now());
    }
}
