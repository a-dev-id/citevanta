<?php

namespace App\Services\Cron;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs due scheduled tasks inside the current PHP process (no proc_open).
 *
 * For each task we look at the most recent time it was due (in its timezone)
 * and the last time we ran it (kept in the cache):
 *  - last run before that due time: run it once now. This also catches up a
 *    run missed while cron was down, but only once.
 *  - already ran at or after that due time: skip. So a task never runs twice
 *    in the same minute, even if cron fires twice.
 *  - never ran: run only if it is due in the current minute; otherwise just
 *    start tracking from now (a fresh install does not run every task at once).
 */
final class InProcessScheduler
{
    public function __construct(private readonly CitevantaSchedule $schedule) {}

    /**
     * @return list<string> commands that ran
     */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $ran = [];

        foreach ($this->schedule->tasks() as $task) {
            $local = $now->setTimezone($task->timezone);
            $lastDue = CarbonImmutable::instance(
                (new CronExpression($task->expression))->getPreviousRunDate($local->toDateTimeImmutable(), 0, true),
            );
            $lastRun = Cache::get($task->cacheKey());

            if ($lastRun === null) {
                if ($lastDue->lessThan($local->startOfMinute())) {
                    Cache::forever($task->cacheKey(), $now->getTimestamp());

                    continue;
                }
            } elseif ((int) $lastRun >= $lastDue->getTimestamp()) {
                continue;
            }

            // Mark first, so a slow or failing task is not started again by an overlapping run.
            Cache::forever($task->cacheKey(), $now->getTimestamp());

            try {
                Artisan::call($task->command);
                $ran[] = $task->command;
            } catch (Throwable $e) {
                Log::error('CiteVanta scheduled task failed', ['task' => $task->command, 'message' => $e->getMessage()]);
            }
        }

        return $ran;
    }

    /**
     * Called when schedule:run (process mode) finishes a task, so last-run
     * times stay correct whichever mode ran the task.
     */
    public function recordFinished(?string $commandLine): void
    {
        foreach ($this->schedule->tasks() as $task) {
            if ($commandLine !== null && str_contains($commandLine, $task->command)) {
                Cache::forever($task->cacheKey(), now()->getTimestamp());
            }
        }
    }

    /**
     * Last run time of every task (null = never), for the System page.
     *
     * @return array<string, ?CarbonImmutable>
     */
    public function lastRuns(): array
    {
        $runs = [];

        foreach ($this->schedule->tasks() as $task) {
            $timestamp = Cache::get($task->cacheKey());
            $runs[$task->command] = $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
        }

        return $runs;
    }
}
