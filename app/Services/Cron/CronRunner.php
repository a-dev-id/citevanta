<?php

namespace App\Services\Cron;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;

/**
 * One cron tick, used by `php artisan citevanta:cron` and GET /cron/{token}.
 *
 *  1. Overlap lock: if the previous run is still going, return "busy".
 *  2. Time budget: CLI has no limit (work window CRON_WORK_SECONDS). A web
 *     request asks set_time_limit(CRON_TIME_LIMIT) and uses what it really got,
 *     minus one job timeout and a 5s margin. If that leaves under 10 seconds,
 *     it processes exactly one job and shortens the OpenAI HTTP timeout.
 *  3. Scheduler: schedule:run when proc_open works, otherwise in-process.
 *  4. Queue: queue:work in this process on the database connection.
 */
final class CronRunner
{
    public const LOCK = 'citevanta-cron';

    public const LAST_RUN_KEY = 'citevanta:cron:last-run';

    public const QUEUES = ['audits', 'scans', 'default', 'google'];

    /** Jobs processed in this PHP process (counted by a JobProcessed listener). */
    private static int $processed = 0;

    public static function countProcessedJob(): void
    {
        self::$processed++;
    }

    public function __construct(
        private readonly RuntimeEnvironment $env,
        private readonly InProcessScheduler $scheduler,
    ) {}

    public function run(): CronResult
    {
        $lock = Cache::lock(self::LOCK, (int) config('citevanta.cron.lock_seconds', 300));

        if (! $lock->get()) {
            Log::info('CiteVanta cron: busy, previous run still going');

            return CronResult::busy();
        }

        $started = microtime(true);

        try {
            [$mode, $limit, $window, $once] = $this->timeBudget();
            [$schedulerMode, $tasks] = $this->runScheduler();
            $processed = $this->processQueue($window, $once);

            $result = new CronResult(
                status: 'ok',
                mode: $mode,
                seconds: microtime(true) - $started,
                schedulerMode: $schedulerMode,
                tasksRun: $tasks,
                jobsProcessed: $processed,
                jobsPending: $this->pendingJobs(),
                timeLimit: $limit,
                workWindow: $window,
                oneJobPerCall: $once,
            );

            Cache::forever(self::LAST_RUN_KEY, $result->toArray() + ['ran_at' => now()->toIso8601String()]);

            Log::info(sprintf(
                'CiteVanta cron: %s, %.1fs, scheduler %s, %d task(s), %d job(s) processed, %d pending',
                $mode, $result->seconds, $schedulerMode, count($tasks), $processed, $result->jobsPending,
            ));

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: bool} mode, effective limit, work window, one job per call
     */
    private function timeBudget(): array
    {
        $this->env->ignoreUserAbort();
        $workSeconds = (int) config('citevanta.cron.work_seconds', 50);

        // CLI: never set a limit (that would turn "unlimited" into 120s).
        if ($this->env->maxExecutionTime() === 0) {
            return ['cli unlimited', 0, $workSeconds, false];
        }

        $requested = (int) config('citevanta.cron.time_limit', 120);
        $this->env->setTimeLimit($requested);
        $effective = $this->env->maxExecutionTime();

        if ($effective === 0) {
            return ['cli unlimited', 0, $workSeconds, false];
        }

        $window = $effective - (int) config('citevanta.job_timeout', 70) - 5;

        if ($window < 10) {
            $this->shortenHttpTimeout(max(5, $effective - 8));

            return ["web limited {$effective}s, one job per call", $effective, max(1, $effective - 5), true];
        }

        $mode = $effective >= $requested ? "web extended to {$effective}s" : "web limited {$effective}s";

        return [$mode, $effective, $window, false];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function runScheduler(): array
    {
        if (! $this->env->procOpenAvailable()) {
            return ['in-process', $this->scheduler->runDue()];
        }

        $before = $this->scheduler->lastRuns();
        Artisan::call('schedule:run');
        $after = $this->scheduler->lastRuns();

        $ran = array_keys(array_filter($after, fn ($time, $command) => $time !== null && $time != ($before[$command] ?? null), ARRAY_FILTER_USE_BOTH));

        return ['process', array_values($ran)];
    }

    private function processQueue(int $window, bool $once): int
    {
        $before = self::$processed;

        Artisan::call('queue:work', array_filter([
            'connection' => 'database',
            '--queue' => implode(',', self::QUEUES),
            '--stop-when-empty' => true,
            '--once' => $once,
            '--max-time' => max(1, $window),
            '--tries' => 3,
            '--timeout' => (int) config('citevanta.job_timeout', 70),
            '--memory' => 256,
            '--sleep' => 1,
        ], fn ($value) => $value !== false));

        return self::$processed - $before;
    }

    private function pendingJobs(): int
    {
        return DB::table((string) config('queue.connections.database.table', 'jobs'))
            ->whereIn('queue', self::QUEUES)
            ->count();
    }

    /**
     * A web call that can only fit one job: make sure one OpenAI request
     * cannot outlast the PHP time limit.
     */
    private function shortenHttpTimeout(int $seconds): void
    {
        config(['openai.request_timeout' => $seconds]);
        app()->forgetInstance(ClientContract::class);
        Facade::clearResolvedInstance('openai');
    }
}
