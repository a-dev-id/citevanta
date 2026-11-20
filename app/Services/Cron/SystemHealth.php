<?php

namespace App\Services\Cron;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Health information for Admin > System and GET /cron/{token}/health.
 */
final class SystemHealth
{
    public function __construct(
        private readonly RuntimeEnvironment $env,
        private readonly CitevantaSchedule $schedule,
        private readonly InProcessScheduler $scheduler,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $last = Cache::get(CronRunner::LAST_RUN_KEY);
        $lastAt = isset($last['ran_at']) ? CarbonImmutable::parse($last['ran_at']) : null;
        $minutesSince = $lastAt?->diffInMinutes(now());
        $staleAfter = (int) config('citevanta.cron.stale_after_minutes', 15);
        $jobsTable = (string) config('queue.connections.database.table', 'jobs');
        $oldestJob = DB::table($jobsTable)->min('created_at');
        $lastRuns = $this->scheduler->lastRuns();

        return [
            'php_version' => $this->env->phpVersion(),
            'memory_limit' => $this->env->memoryLimit(),
            'cron' => [
                'last_run_at' => $lastAt?->toIso8601String(),
                'minutes_since_last_run' => $minutesSince === null ? null : (int) floor($minutesSince),
                'warning' => $lastAt === null || $minutesSince > $staleAfter ? 'Cron may not be running' : null,
                'mode' => $last['mode'] ?? null,
                'scheduler' => $last['scheduler'] ?? null,
                'effective_time_limit' => $last['time_limit'] ?? null,
                'work_window' => $last['work_window'] ?? null,
                'last_jobs_processed' => $last['jobs_processed'] ?? null,
            ],
            'set_time_limit_works' => $this->setTimeLimitWorks(),
            'proc_open_available' => $this->env->procOpenAvailable(),
            'scheduler_mode' => $this->env->procOpenAvailable() ? 'process' : 'in-process',
            'queue' => [
                'driver' => (string) config('queue.default'),
                'pending_jobs' => DB::table($jobsTable)->count(),
                'oldest_pending_seconds' => $oldestJob === null ? null : max(0, now()->getTimestamp() - (int) $oldestJob),
                'failed_jobs' => DB::table((string) config('queue.failed.table', 'failed_jobs'))->count(),
            ],
            'scheduled_tasks' => array_map(fn (ScheduledTask $task) => [
                'command' => $task->command,
                'expression' => $task->expression,
                'timezone' => $task->timezone,
                'description' => $task->description,
                'last_run_at' => $lastRuns[$task->command]?->toIso8601String(),
            ], $this->schedule->tasks()),
        ];
    }

    /**
     * Settings that must be fixed before running in production.
     *
     * @return list<string>
     */
    public function productionWarnings(): array
    {
        $warnings = [];

        if (config('app.debug') && app()->isProduction()) {
            $warnings[] = 'APP_DEBUG is true in production. Set APP_DEBUG=false: error pages can show secrets.';
        }

        if (config('app.debug') && ! app()->isProduction() && app()->environment() !== 'local' && app()->environment() !== 'testing') {
            $warnings[] = 'APP_DEBUG is true. Set APP_DEBUG=false on any public server.';
        }

        if (blank(config('citevanta.cron.token'))) {
            $warnings[] = 'CRON_TOKEN is empty, so the fallback URL cron is disabled. Set a random token of at least 40 characters.';
        } elseif (strlen((string) config('citevanta.cron.token')) < 40) {
            $warnings[] = 'CRON_TOKEN is shorter than 40 characters. Use a longer random token.';
        }

        if (config('citevanta.engine') === 'openai' && blank(config('openai.api_key'))) {
            $warnings[] = 'OPENAI_API_KEY is empty while CITEVANTA_ENGINE=openai: scans will fail.';
        }

        if (app()->isProduction() && config('citevanta.engine') !== 'openai') {
            $warnings[] = 'CITEVANTA_ENGINE is not "openai": production scans use the fake engine.';
        }

        return $warnings;
    }

    /**
     * The two cron lines to paste into cPanel (admins only: includes the token).
     *
     * @return array{command: string, url: ?string}
     */
    public function cronLines(): array
    {
        $token = (string) config('citevanta.cron.token');

        return [
            'command' => '* * * * * /usr/local/bin/php "'.base_path('artisan').'" citevanta:cron >/dev/null 2>&1',
            'url' => $token === '' ? null : '* * * * * wget -q -O /dev/null "'.route('cron.run', ['token' => $token]).'"',
        ];
    }

    /**
     * True/false in a request with a time limit; null when there is no limit
     * to change (CLI).
     */
    private function setTimeLimitWorks(): ?bool
    {
        $current = $this->env->maxExecutionTime();

        if ($current === 0) {
            return null;
        }

        $this->env->setTimeLimit($current + 30);

        return $this->env->maxExecutionTime() === $current + 30;
    }
}
