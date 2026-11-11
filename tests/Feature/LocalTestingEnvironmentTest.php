<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\TestRun;
use App\Models\User;
use App\Services\CostControl;
use App\Services\CreditLedger;
use App\Services\OrganizationQuota;
use App\Services\SubscriptionFinancialProtection;
use App\Services\TenantContext;
use App\Services\VisibilityMetrics;
use Database\Seeders\LocalTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalTestingEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['local_testing.credentials_path' => storage_path('framework/unit-testing-credentials.json')]);
        File::delete(config('local_testing.credentials_path'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::delete(config('local_testing.credentials_path'));
        parent::tearDown();
    }

    private function fixtures(): void
    {
        $this->seed(LocalTestingSeeder::class);
    }

    private function select(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $org = $user->organizations()->first() ?? Organization::where('slug', 'citevanta-test-free')->firstOrFail();
        $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->first();
        app(TenantContext::class)->set($org->id, $brand->id, $user);
        $this->actingAs($user)->withSession(['organization_id' => $org->id, 'monitored_brand_id' => $brand->id]);

        return $user;
    }

    public function test_seed_is_idempotent_and_preserves_original_workspace_and_credits(): void
    {
        $original = DB::table('organizations')->where('id', 1)->first();
        $this->fixtures();
        $counts = [Organization::count(), User::count(), DB::table('test_runs')->count(), DB::table('search_prompts')->count()];
        $credentials = File::get(config('local_testing.credentials_path'));
        $this->fixtures();
        $this->assertSame($counts, [Organization::count(), User::count(), DB::table('test_runs')->count(), DB::table('search_prompts')->count()]);
        $this->assertSame($credentials, File::get(config('local_testing.credentials_path')));
        $this->assertEquals($original, DB::table('organizations')->where('id', 1)->first());
        $this->assertSame(10, User::where('is_test_fixture', true)->whereNotNull('email_verified_at')->count());
        $this->assertSame(48, DB::table('test_runs')->where('is_simulated', true)->count());
        foreach (['free' => 5, 'starter' => 450, 'pro' => 1500, 'agency' => 4000] as $plan => $allowance) {
            $this->select($plan.'@example.test');
            $balance = app(CreditLedger::class)->balance();
            $this->assertSame($allowance, $balance['remaining']);
            $this->assertSame(0, $balance['used'] + $balance['reserved']);
            $this->assertSame(12, TestRun::count());
            $this->assertCount(10, app(VisibilityMetrics::class)->runs());
            $this->get('/dashboard')->assertOk()->assertSee('SIMULATED TEST DATA');
        }
        Http::assertNothingSent();
    }

    public function test_all_accounts_have_role_appropriate_pages_and_no_financial_interfaces(): void
    {
        $this->fixtures();
        foreach (LocalTestingSeeder::EMAILS as $email) {
            $user = $this->select($email);
            foreach (['/dashboard', '/history', '/batches', '/organization?section=usage', '/organization?section=brands', '/settings'] as $path) {
                $this->get($path)->assertOk()->assertDontSee('Cost Controls')->assertDontSee('Estimated cost')->assertDontSee('Input tokens')->assertDontSee('monthly_limit')->assertDontSee('USD');
            }
            $this->get('/batch-settings')->assertNotFound();
            $this->put('/batch-settings', ['monthly_limit' => '100'])->assertNotFound();
            $this->get('/platform/organizations')->assertStatus($user->is_platform_admin ? 200 : 403);
            if (str_contains($email, 'viewer')) {
                $this->get('/tests')->assertForbidden();
                $this->post('/batches/preview', [])->assertForbidden();
                $this->put('/organization', ['name' => 'Forged', 'plan_key' => 'agency'])->assertForbidden();
            } elseif (! $user->is_platform_admin) {
                $this->put('/organization/plan', ['plan_key' => 'agency'])->assertForbidden();
            }
        }
        Http::assertNothingSent();
    }

    public function test_plan_form_binds_selected_workspace_not_last_switcher_option(): void
    {
        $this->fixtures();
        $this->select('platform-admin@example.test');
        $this->post('/organization/switch', ['organization_id' => 1])->assertRedirect();
        $this->get('/organization?section=brands')->assertOk()->assertSee('value="agency" selected', false)->assertDontSee('id="organization-limit"', false);
        $this->assertSame('agency', Organization::findOrFail(1)->plan_key);
    }

    public function test_simulated_workspaces_cannot_send_provider_requests_even_if_caps_are_raised(): void
    {
        $this->fixtures();
        $user = $this->select('agency@example.test');
        app(TenantContext::class)->organization()->forceFill(['monthly_limit_micros' => 100000000])->save();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('demonstration workspace');
        app(OrganizationQuota::class)->assertRequest('openai', 1, $user->id);
    }

    public function test_customer_cannot_switch_to_another_test_organization_or_read_its_results(): void
    {
        $this->fixtures();
        $this->select('free@example.test');
        $other = Organization::where('slug', 'citevanta-test-pro')->firstOrFail();
        $run = DB::table('test_runs')->where('organization_id', $other->id)->first();
        $this->post('/organization/switch', ['organization_id' => $other->id])->assertNotFound();
        $this->get('/tests/'.$run->id)->assertNotFound();
        $this->getJson('/tests/'.$run->id)->assertNotFound();
        $this->get('/batches/create')->assertForbidden();
    }

    public function test_seeder_refuses_production_and_unrelated_email_collisions(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(LocalTestingSeeder::class)->run();
            $this->fail('Production seeding must fail');
        } catch (\RuntimeException) {
            $this->assertSame(0, Organization::where('is_test_fixture', true)->count());
        }
        $this->app->instance('env', 'testing');
        User::factory()->create(['email' => 'free@example.test']);
        $this->expectException(\RuntimeException::class);
        app(LocalTestingSeeder::class)->run();
    }

    public function test_removal_and_reset_only_touch_marked_test_fixtures(): void
    {
        $original = DB::table('organizations')->where('id', 1)->first();
        $this->fixtures();
        $this->artisan('testing:fixtures', ['action' => 'reset', '--confirm-test-fixtures' => true])->assertSuccessful();
        $this->assertSame(4, Organization::where('is_test_fixture', true)->count());
        $this->artisan('testing:fixtures', ['action' => 'remove', '--confirm-test-fixtures' => true])->assertSuccessful();
        $this->assertSame(0, Organization::where('is_test_fixture', true)->count());
        $this->assertSame(0, User::where('is_test_fixture', true)->count());
        $this->assertEquals($original, DB::table('organizations')->where('id', 1)->first());
        Http::assertNothingSent();
    }

    public function test_automatic_envelope_uses_subscription_allowance_and_global_zero_still_blocks(): void
    {
        $owner = User::factory()->create();
        $this->joinLegacyWorkspace($owner);
        $this->assertGreaterThan(0, app(SubscriptionFinancialProtection::class)->ceiling());
        app(CostControl::class)->settings()->update(['monthly_limit_micros' => 0]);
        $this->expectException(ValidationException::class);
        app(CostControl::class)->assertAvailable(1);
    }

    public function test_platform_can_manage_status_and_plan_without_joining_or_changing_execution_limits(): void
    {
        $this->fixtures();
        $platform = $this->select('platform-admin@example.test');
        $organization = app(TenantContext::class)->organization();
        $this->put('/organization', ['name' => $organization->name, 'status' => 'suspended', 'plan_key' => 'starter'])->assertRedirect();
        $this->assertSame('suspended', $organization->fresh()->status);
        $this->assertSame('starter', $organization->fresh()->plan_key);
        $this->get('/organization?section=brands')->assertOk();
        $this->put('/organization', ['name' => $organization->name, 'status' => 'active', 'plan_key' => 'free'])->assertRedirect();
        $this->assertSame(0, $organization->fresh()->monthly_limit_micros);
        $this->assertFalse($organization->members()->where('users.id', $platform->id)->exists());
        $this->assertSame(1, $organization->members()->count());
        Http::assertNothingSent();
    }

    public function test_removal_preserves_a_fixture_account_referenced_by_an_unrelated_organization(): void
    {
        $this->fixtures();
        $platform = User::where('email', 'platform-admin@example.test')->firstOrFail();
        Organization::findOrFail(1)->members()->attach($platform->id, ['role' => 'viewer']);
        $this->artisan('testing:fixtures', ['action' => 'remove', '--confirm-test-fixtures' => true])->assertSuccessful();
        $this->assertNotNull($platform->fresh());
        $saved = json_decode(File::get(config('local_testing.credentials_path')), true);
        $this->assertArrayHasKey($platform->email, $saved);
        $this->assertSame(1, Organization::count());
        $this->fixtures();
        $this->assertSame(4, Organization::where('is_test_fixture', true)->count());
        $this->assertTrue(Organization::findOrFail(1)->members()->where('users.id', $platform->id)->exists());
    }
}
