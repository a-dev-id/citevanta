<?php

namespace App\Services\Cron;

use Illuminate\Console\Scheduling\Schedule;

/**
 * The one place the scheduled tasks are defined. routes/console.php registers
 * them with Laravel's scheduler (schedule:run); CronRunner runs them
 * in-process when proc_open is not available.
 */
final class CitevantaSchedule
{
    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        $timezone = (string) config('citevanta.schedule_timezone', 'Asia/Makassar');

        return [
            new ScheduledTask('citevanta:dispatch-queued-scans', '*/5 * * * *', $timezone, 'Start scans that are still queued'),
            new ScheduledTask('citevanta:fail-stuck-scans', '0 * * * *', $timezone, 'Close scans with no progress for 90 minutes'),
            new ScheduledTask('citevanta:dispatch-due-scans', '0 1 * * *', $timezone, 'Queue scheduled scans'),
            new ScheduledTask('citevanta:housekeeping', '30 2 * * *', $timezone, 'Prune old job batches and failed jobs'),
            new ScheduledTask('citevanta:billing', '15 * * * *', $timezone, 'Billing: renewal invoices, reminders, past due, expiry'),
            new ScheduledTask('citevanta:audit-waitlist', '0 8 * * *', $timezone, 'Email the free audit waitlist that slots are open'),
            new ScheduledTask('citevanta:google-sync', '20 3 * * *', $timezone, 'Queue the daily GA4 and Search Console sync'),
            new ScheduledTask('citevanta:google-heal', '20 5 * * *', $timezone, 'Queue GA4 days that are missing from the stored data'),
            new ScheduledTask('citevanta:google-health', '40 3 * * 1', $timezone, 'Weekly tracking health check'),
            // Hourly: each team gets its report at Monday 08:00 in its own timezone.
            new ScheduledTask('citevanta:weekly-reports', '1 * * * *', $timezone, 'Weekly email reports'),
        ];
    }

    public function register(Schedule $schedule): void
    {
        foreach ($this->tasks() as $task) {
            $schedule->command($task->command)
                ->cron($task->expression)
                ->timezone($task->timezone)
                ->withoutOverlapping()
                ->description($task->description);
        }
    }
}
