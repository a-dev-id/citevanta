<?php

namespace Tests\Feature\Cron;

use App\Services\Cron\CronRunner;
use App\Services\Cron\InProcessScheduler;
use App\Services\Cron\RuntimeEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\NoopJob;
use Tests\TestCase;

class CronRunnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A runtime where max_execution_time starts at $limit and set_time_limit()
     * either works or is ignored (as on some shared hosts).
     */
    private function environment(int $limit, bool $setTimeLimitWorks = true, bool $procOpen = false): RuntimeEnvironment
    {
        $env = new class extends RuntimeEnvironment
        {
            public int $limit = 0;

            public bool $canSet = true;

            public bool $procOpen = false;

            public function maxExecutionTime(): int
            {
                return $this->limit;
            }

            public function setTimeLimit(int $seconds): bool
            {
                if ($this->canSet) {
                    $this->limit = $seconds;
                }

                return $this->canSet;
            }

            public function ignoreUserAbort(): void {}

            public function procOpenAvailable(): bool
            {
                return $this->procOpen;
            }
        };

        $env->limit = $limit;
        $env->canSet = $setTimeLimitWorks;
        $env->procOpen = $procOpen;
        $this->app->instance(RuntimeEnvironment::class, $env);

        return $env;
    }

    private function runCron(): array
    {
        return app(CronRunner::class)->run()->toArray();
    }

    public function test_cli_without_a_time_limit_uses_the_work_window(): void
    {
        $env = $this->environment(0);

        $result = $this->runCron();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('cli unlimited', $result['mode']);
        $this->assertSame(50, $result['work_window']);
        $this->assertFalse($result['one_job_per_call']);
        $this->assertSame(0, $env->limit, 'CLI must not get a time limit');
    }

    public function test_web_request_extended_by_set_time_limit(): void
    {
        $this->environment(30);

        $result = $this->runCron();

        $this->assertSame('web extended to 120s', $result['mode']);
        $this->assertSame(120, $result['time_limit']);
        $this->assertSame(120 - 70 - 5, $result['work_window']);
        $this->assertFalse($result['one_job_per_call']);
    }

    public function test_web_request_stuck_at_30_seconds_processes_one_job_with_a_short_http_timeout(): void
    {
        $this->environment(30, setTimeLimitWorks: false);

        $result = $this->runCron();

        $this->assertSame('web limited 30s, one job per call', $result['mode']);
        $this->assertTrue($result['one_job_per_call']);
        $this->assertSame(22, config('openai.request_timeout'), 'limit - 8 seconds');
    }

    public function test_a_second_run_while_the_first_holds_the_lock_returns_busy(): void
    {
        $this->environment(0);
        $lock = Cache::lock(CronRunner::LOCK, 300);
        $lock->get();

        $this->assertSame(['status' => 'busy'], array_intersect_key($this->runCron(), ['status' => true]));

        $lock->release();
        $this->assertSame('ok', $this->runCron()['status']);
    }

    public function test_queued_jobs_are_processed_in_process(): void
    {
        $this->environment(0);
        dispatch((new NoopJob)->onConnection('database')->onQueue('scans'));
        dispatch((new NoopJob)->onConnection('database')->onQueue('default'));

        $result = $this->runCron();

        $this->assertSame(2, $result['jobs_processed']);
        $this->assertSame(0, $result['jobs_pending']);
    }

    public function test_one_job_per_call_mode_processes_exactly_one_job(): void
    {
        $this->environment(30, setTimeLimitWorks: false);
        dispatch((new NoopJob)->onConnection('database')->onQueue('scans'));
        dispatch((new NoopJob)->onConnection('database')->onQueue('scans'));

        $result = $this->runCron();

        $this->assertSame(1, $result['jobs_processed']);
        $this->assertSame(1, $result['jobs_pending']);
    }

    public function test_without_proc_open_due_tasks_run_in_process(): void
    {
        $this->environment(0, procOpen: false);
        // 10:05 Makassar = 02:05 UTC: the every-5-minutes task is due now.
        $this->travelTo(Carbon::parse('2026-11-18 02:05:10', 'UTC'));

        $result = $this->runCron();

        $this->assertSame('in-process', $result['scheduler']);
        $this->assertSame(['citevanta:dispatch-queued-scans'], $result['tasks_run']);
    }

    public function test_with_proc_open_schedule_run_is_used(): void
    {
        $this->environment(0, procOpen: true);
        $this->travelTo(Carbon::parse('2026-11-18 02:07:00', 'UTC')); // nothing due at :07

        $result = $this->runCron();

        $this->assertSame('process', $result['scheduler']);
        $this->assertSame([], $result['tasks_run']);
    }

    public function test_the_last_run_is_stored_for_the_system_page(): void
    {
        $this->environment(0);

        $this->runCron();

        $this->assertSame('cli unlimited', Cache::get(CronRunner::LAST_RUN_KEY)['mode']);
        $this->assertNotNull(Cache::get(CronRunner::LAST_RUN_KEY)['ran_at']);
    }

    public function test_in_process_scheduler_never_runs_a_task_twice_in_the_same_minute(): void
    {
        $scheduler = app(InProcessScheduler::class);
        $now = Carbon::parse('2026-11-18 02:05:10', 'UTC');

        $this->assertSame(['citevanta:dispatch-queued-scans'], $scheduler->runDue($now));
        $this->assertSame([], $scheduler->runDue($now->copy()->addSeconds(30)));
        $this->assertSame([], $scheduler->runDue($now->copy()->addMinutes(3)), 'not due again until :10');
        $this->assertSame(['citevanta:dispatch-queued-scans'], $scheduler->runDue(Carbon::parse('2026-11-18 02:10:05', 'UTC')));
    }

    public function test_in_process_scheduler_catches_up_a_missed_daily_task_once(): void
    {
        $scheduler = app(InProcessScheduler::class);

        // Ran on 17 Nov at 01:00 Makassar (16 Nov 17:00 UTC) ...
        $scheduler->runDue(Carbon::parse('2026-11-16 17:00:20', 'UTC'));
        // ... then cron was down over 01:00 on 18 Nov (17 Nov 17:00 UTC) and came back at 03:12 Makassar.
        $backAt = Carbon::parse('2026-11-17 19:12:00', 'UTC');

        $this->assertContains('citevanta:dispatch-due-scans', $scheduler->runDue($backAt));
        $this->assertNotContains('citevanta:dispatch-due-scans', $scheduler->runDue($backAt->copy()->addMinute()), 'caught up only once');
    }

    public function test_a_fresh_install_does_not_run_every_task_at_once(): void
    {
        // 02:07 UTC: no task is due this minute, and nothing has run before.
        $this->assertSame([], app(InProcessScheduler::class)->runDue(Carbon::parse('2026-11-18 02:07:00', 'UTC')));
    }
}
