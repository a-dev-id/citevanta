<?php

namespace Tests\Feature\Cron;

use App\Services\Cron\RuntimeEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronUrlTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789-0123456789-0123456789-abc';

    protected function setUp(): void
    {
        parent::setUp();

        config(['citevanta.cron.token' => self::TOKEN]);

        // Simulate the CLI-like case so the test does not touch real time limits.
        $this->app->instance(RuntimeEnvironment::class, new class extends RuntimeEnvironment
        {
            public function maxExecutionTime(): int
            {
                return 0;
            }

            public function ignoreUserAbort(): void {}

            public function procOpenAvailable(): bool
            {
                return false;
            }
        });
    }

    public function test_the_right_token_runs_cron_and_returns_json_without_cookies(): void
    {
        $response = $this->get('/cron/'.self::TOKEN);

        $response->assertOk()
            ->assertJsonStructure(['status', 'mode', 'seconds', 'scheduler', 'tasks_run', 'jobs_processed', 'jobs_pending'])
            ->assertJson(['status' => 'ok', 'scheduler' => 'in-process']);

        $this->assertEmpty($response->headers->getCookies(), 'no session cookie on the cron URL');
    }

    public function test_a_wrong_or_missing_token_is_a_404(): void
    {
        $this->get('/cron/wrong-token')->assertNotFound();
        $this->get('/cron/'.self::TOKEN.'x')->assertNotFound();
        $this->get('/cron')->assertNotFound();
    }

    public function test_an_empty_token_disables_the_url(): void
    {
        config(['citevanta.cron.token' => '']);

        $this->get('/cron/'.self::TOKEN)->assertNotFound();
        $this->get('/cron/')->assertNotFound();
    }

    public function test_health_returns_the_system_information(): void
    {
        $this->get('/cron/'.self::TOKEN.'/health')
            ->assertOk()
            ->assertJsonStructure([
                'php_version', 'memory_limit', 'set_time_limit_works', 'proc_open_available', 'scheduler_mode',
                'cron' => ['last_run_at', 'minutes_since_last_run', 'warning', 'mode', 'effective_time_limit'],
                'queue' => ['driver', 'pending_jobs', 'oldest_pending_seconds', 'failed_jobs'],
                'scheduled_tasks' => [['command', 'expression', 'timezone', 'last_run_at']],
            ])
            ->assertJsonPath('cron.warning', 'Cron may not be running')
            ->assertDontSee(self::TOKEN);

        $this->get('/cron/wrong/health')->assertNotFound();
    }

    public function test_the_cron_url_is_throttled_to_six_calls_a_minute(): void
    {
        foreach (range(1, 6) as $i) {
            $this->get('/cron/wrong-token')->assertNotFound();
        }

        $this->get('/cron/wrong-token')->assertTooManyRequests();
    }

    public function test_the_command_prints_the_result(): void
    {
        $this->artisan('citevanta:cron')
            ->expectsOutputToContain('mode:      cli unlimited')
            ->expectsOutputToContain('scheduler: in-process')
            ->assertSuccessful();
    }
}
