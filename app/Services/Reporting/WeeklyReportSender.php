<?php

namespace App\Services\Reporting;

use App\Mail\WeeklyReport;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Runs hourly. A team is handled once per ISO week, at the first run on or
 * after Monday 08:00 in its timezone. The report goes out only when a scan
 * finished since the previous report, to owners and members who opted in
 * (User::wantsWeeklyReport). Mail is queued and throttled (ThrottledMail).
 */
final class WeeklyReportSender
{
    public function __construct(private readonly WeeklyReportBuilder $builder) {}

    /**
     * @return array{teams: int, emails: int, skipped: int}
     */
    public function run(): array
    {
        $result = ['teams' => 0, 'emails' => 0, 'skipped' => 0];

        Team::query()->whereHas('hotels')->each(function (Team $team) use (&$result): void {
            $local = now($team->timezoneName());
            $week = $local->format('o-\WW');

            if (! $this->isDue($local) || $team->weekly_report_week === $week) {
                return;
            }

            $since = $team->weekly_report_sent_at ?? now()->subDays(7);
            $hotels = $this->builder->build($team, $since);
            $team->forceFill(['weekly_report_week' => $week])->save();

            if ($hotels === []) {
                $result['skipped']++;

                return;
            }

            $recipients = $team->users()->get()->filter(fn (User $user) => $user->wantsWeeklyReport($team));

            foreach ($recipients as $user) {
                Mail::to($user)->queue(new WeeklyReport($user, $team, $hotels));
                $result['emails']++;
            }

            $team->forceFill(['weekly_report_sent_at' => now()])->save();
            $result['teams']++;
        });

        return $result;
    }

    /**
     * The report day (Monday), from the report hour (08:00) on.
     */
    private function isDue(CarbonInterface $local): bool
    {
        return $local->dayOfWeekIso === (int) config('citevanta.weekly_report.day', 1)
            && $local->hour >= (int) config('citevanta.weekly_report.hour', 8);
    }
}
