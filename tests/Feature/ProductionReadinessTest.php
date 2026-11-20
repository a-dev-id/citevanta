<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Deploy\DeployTasks;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_config_and_routes_can_be_cached(): void
    {
        // Relative to the project: Laravel treats "D:\..." as relative on Windows.
        $relative = 'storage/framework/testing/cache-test-'.uniqid();
        $dir = base_path($relative);
        mkdir($dir, 0777, true);
        $paths = ['APP_CONFIG_CACHE' => $dir.'/config.php', 'APP_ROUTES_CACHE' => $dir.'/routes.php'];

        foreach (['APP_CONFIG_CACHE' => $relative.'/config.php', 'APP_ROUTES_CACHE' => $relative.'/routes.php'] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $this->assertSame(0, Artisan::call('config:cache'), Artisan::output());
            $this->assertFileExists($paths['APP_CONFIG_CACHE']);

            $this->assertSame(0, Artisan::call('route:cache'), Artisan::output());
            $this->assertFileExists($paths['APP_ROUTES_CACHE']);
        } finally {
            foreach ($paths as $key => $path) {
                @unlink($path);
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            @rmdir($dir);
        }
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_production_redirects_http_to_https(): void
    {
        $this->asProduction();

        $this->get('http://citevanta.test/login?x=1')->assertRedirect('https://citevanta.test/login?x=1')->assertStatus(301);
        $this->get('https://citevanta.test/login')->assertOk()->assertHeader('Strict-Transport-Security');
    }

    public function test_make_admin_creates_a_verified_admin_with_a_team(): void
    {
        $this->artisan('citevanta:make-admin', ['email' => 'Owner@Example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Admin owner@example.com created.')
            ->expectsOutputToContain('Password (shown once')
            ->assertSuccessful();

        $admin = User::where('email', 'owner@example.com')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($admin->currentTeam);
    }

    public function test_make_admin_promotes_an_existing_user_and_rejects_bad_emails(): void
    {
        $user = User::factory()->withTeam()->create(['email' => 'jane@example.com']);

        $this->artisan('citevanta:make-admin', ['email' => 'jane@example.com'])->expectsOutputToContain('is now an admin')->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->assertSame(1, User::count());

        $this->artisan('citevanta:make-admin', ['email' => 'not-an-email'])->assertExitCode(2);
    }

    public function test_the_demo_seeder_is_blocked_in_production_without_force(): void
    {
        $this->asProduction();

        $this->artisan('db:seed', ['--class' => DemoSeeder::class])
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->expectsOutputToContain('DemoSeeder is blocked in production');

        $this->assertSame(0, User::where('email', DemoSeeder::DEMO_EMAIL)->count());

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertSame(1, User::where('email', DemoSeeder::DEMO_EMAIL)->count());
    }

    public function test_system_page_warns_about_unsafe_production_settings(): void
    {
        $admin = User::factory()->admin()->withTeam()->create();
        $this->asProduction();
        config(['app.debug' => true, 'citevanta.cron.token' => '', 'citevanta.engine' => 'openai', 'openai.api_key' => '']);

        $this->actingAs($admin)->get('https://citevanta.test/admin/system')
            ->assertOk()
            ->assertSee('Fix before going live')
            ->assertSee('APP_DEBUG is true in production')
            ->assertSee('CRON_TOKEN is empty')
            ->assertSee('OPENAI_API_KEY is empty');
    }

    public function test_safe_settings_show_no_warnings(): void
    {
        $admin = User::factory()->admin()->withTeam()->create();
        config(['app.debug' => false, 'citevanta.cron.token' => str_repeat('a', 48), 'citevanta.engine' => 'fake']);

        $this->actingAs($admin)->get('/admin/system')->assertOk()->assertDontSee('Fix before going live');
    }

    public function test_deploy_tasks_run_in_order_for_admins_only(): void
    {
        $ran = [];
        $this->app->instance(DeployTasks::class, new class($ran) extends DeployTasks
        {
            public function __construct(private array &$ran) {}

            protected function call(string $command, array $arguments): array
            {
                $this->ran[] = $command;

                return ['task' => $command, 'ok' => true, 'output' => 'Done.'];
            }

            public function symlinksAllowed(): bool
            {
                return false;
            }
        });

        $admin = User::factory()->admin()->withTeam()->create();

        $this->actingAs($admin)->post('/admin/system/deploy-tasks')
            ->assertRedirect('/admin/system')
            ->assertSessionHas('deploy_results', fn (array $results) => end($results)['task'] === 'storage:link'
                && str_contains(end($results)['output'], 'served through the /storage route'));
        $this->assertSame(['migrate', 'config:cache', 'route:cache', 'view:cache', 'event:cache'], $ran);

        $this->actingAs($admin)->post('/admin/system/clear-caches')->assertRedirect('/admin/system');
        $this->assertSame('optimize:clear', end($ran));

        $this->actingAs(User::factory()->withTeam()->create())->post('/admin/system/deploy-tasks')->assertForbidden();
    }

    public function test_public_files_are_served_through_the_fallback_route(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/hotel.txt', 'logo');

        $this->get('/storage/logos/hotel.txt')->assertOk();
        $this->get('/storage/logos/missing.txt')->assertNotFound();
        $this->get('/storage/../.env')->assertNotFound();
        $this->get('/storage/logos/..%2F..%2F.env')->assertNotFound();
    }
}
