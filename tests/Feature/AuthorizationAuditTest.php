<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Hotel;
use App\Models\Improvement;
use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\CreditLedger;
use App\Services\TenantContext;
use Database\Seeders\LocalTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['local_testing.credentials_path' => storage_path('framework/authorization-audit-'.getmypid().'.json'), 'local_testing.simulated_execution' => true]);
        $this->seed(LocalTestingSeeder::class);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        File::delete(config('local_testing.credentials_path'));
        parent::tearDown();
    }

    private function select(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        $organization = $user->organizations()->firstOrFail();
        $brand = DB::table('monitored_brands')->where('organization_id', $organization->id)->first();
        app(TenantContext::class)->set($organization->id, $brand->id, $user);
        $this->actingAs($user)->withSession(['organization_id' => $organization->id, 'monitored_brand_id' => $brand->id]);

        return $user;
    }

    public function test_every_workspace_write_route_rejects_direct_viewer_requests(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->select('pro-viewer@example.test');
        $run = TestRun::whereHas('response.citations')->firstOrFail();
        $identity = Hotel::firstOrFail();
        $improvement = Improvement::factory()->create(['hotel_id' => $identity->id]);
        $parameters = ['testRun' => $run->id, 'citation' => $run->response->citations->first()->id, 'batch' => TestBatch::firstOrFail()->id, 'prompt' => SearchPrompt::firstOrFail()->id, 'hotel' => $identity->id, 'brand' => app(TenantContext::class)->brandId, 'member' => auth()->id(), 'improvement' => $improvement->id];
        $before = DB::table('test_runs')->get()->toJson();
        $checked = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('admin', $route->middleware(), true) || $route->getName() === 'brands.switch') {
                continue;
            }
            $url = '/'.$route->uri();
            foreach ($parameters as $key => $value) {
                $url = str_replace('{'.$key.'}', (string) $value, $url);
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $response = $this->call($method, $url);
                $this->assertSame(str_starts_with($route->getName(), 'pilot.') ? 404 : 403, $response->status(), $method.' '.$url);
                $checked[] = $route->getName();
            }
        }
        $this->assertGreaterThanOrEqual(25, count($checked));
        $this->assertSame($before, DB::table('test_runs')->get()->toJson());
        foreach (['/tests', '/prompts/create', '/prompts/'.$parameters['prompt'].'/edit', '/batches/create', '/platform/organizations', '/organizations/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_customer_managers_cannot_self_assign_plans_or_escape_organization(): void
    {
        foreach (['pro@example.test', 'pro-admin@example.test'] as $email) {
            $this->select($email);
            $organization = app(TenantContext::class)->organization();
            $this->put('/organization/plan', ['plan_key' => 'agency'])->assertForbidden();
            $this->put('/organization', ['name' => 'Changed', 'plan_key' => 'agency', 'is_platform_admin' => true])->assertForbidden();
            $this->get('/platform/organizations')->assertForbidden();
            $this->post('/organization/switch', ['organization_id' => Organization::where('slug', 'nandini-jungle')->value('id')])->assertNotFound();
            $foreign = DB::table('monitored_brands')->where('organization_id', '!=', $organization->id)->first();
            $this->post('/brand/switch', ['monitored_brand_id' => $foreign->id])->assertNotFound();
            $this->assertSame('pro', $organization->fresh()->plan_key);
            $this->assertFalse(auth()->user()->fresh()->is_platform_admin);
        }
    }

    public static function plans(): array
    {
        return ['free' => ['free', 1, 1, 5], 'starter' => ['starter', 1, 2, 450], 'pro' => ['pro', 5, 5, 1500], 'agency' => ['agency', 10, 20, 4000]];
    }

    #[DataProvider('plans')]
    public function test_http_capacity_boundaries_for_each_plan(string $plan, int $brands, int $members, int $credits): void
    {
        $this->select($plan.'@example.test');
        $organization = app(TenantContext::class)->organization();
        $brandData = ['name' => 'Audit Brand', 'official_website' => 'https://audit.example.test/', 'industry' => 'Software', 'country' => 'AU', 'verified' => 1];
        while (MonitoredBrand::count() < $brands) {
            $this->post('/organization/brands', $brandData)->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('/organization/brands', $brandData)->assertSessionHasErrors('quota');
        while ($organization->members()->count() < $members - 1) {
            $member = User::factory()->create();
            $organization->members()->attach($member->id, ['role' => 'viewer']);
        }
        if ($organization->members()->count() < $members) {
            $this->post('/organization/members', ['name' => 'At capacity', 'email' => 'at-capacity@example.test', 'password' => 'AuditPassword123!', 'role' => 'viewer'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('/organization/members', ['name' => 'Over capacity', 'email' => 'over-capacity@example.test', 'password' => 'AuditPassword123!', 'role' => 'viewer'])->assertSessionHasErrors('quota');
        $this->assertSame($brands, MonitoredBrand::count());
        $this->assertSame($members, $organization->members()->count());
        $this->assertSame($credits, app(CreditLedger::class)->balance()['allowance']);
        $this->assertDatabaseMissing('users', ['email' => 'over-capacity@example.test']);
    }

    #[DataProvider('plans')]
    public function test_last_credit_is_shared_reserved_once_and_cannot_be_overdrawn(string $plan, int $brands, int $members, int $credits): void
    {
        $user = $this->select($plan.'@example.test');
        $ledger = app(CreditLedger::class);
        $period = $ledger->period();
        CreditEntry::create(['organization_id' => $period->organization_id, 'credit_period_id' => $period->id, 'operation_key' => 'audit:prior-usage', 'user_id' => $user->id, 'credits' => $credits - 1, 'status' => 'used', 'plan_key' => $plan, 'provider' => 'simulated', 'model' => 'simulated-monitoring-v1']);
        $last = $ledger->reserve('audit:last', $user->id, 'simulated', 'simulated-monitoring-v1');
        $this->assertSame($last->id, $ledger->reserve('audit:last', $user->id, 'simulated', 'simulated-monitoring-v1')->id);
        if ($brands > 1) {
            $brand = MonitoredBrand::factory()->create();
            app(TenantContext::class)->set($period->organization_id, $brand->id, $user);
        }
        if ($members > 1) {
            $user = User::where('email', $plan.'-admin@example.test')->firstOrFail();
            app(TenantContext::class)->set($period->organization_id, app(TenantContext::class)->brandId, $user);
        }
        $this->assertSame(0, $ledger->balance()['remaining']);
        try {
            $ledger->reserve('audit:overdraw', $user->id, 'simulated', 'simulated-monitoring-v1');
            $this->fail('The shared organization allowance must not be exceeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credits', $exception->errors());
        }
        $this->assertSame(2, CreditEntry::where('organization_id', $period->organization_id)->count());
        $this->assertSame($credits - 1, $ledger->balance()['used']);
        $this->assertSame(1, $ledger->balance()['reserved']);
    }

    public function test_platform_label_preserves_effective_permissions(): void
    {
        $this->select('pro-viewer@example.test');
        $platform = User::where('email', 'platform-admin@example.test')->firstOrFail();
        $context = app(TenantContext::class);
        $this->assertSame('administrator', $context->role($platform));
        $this->assertSame('Platform Administrator', $context->roleLabel($platform));
        $this->assertTrue($context->canManage($platform));
        $this->assertFalse($platform->organizations()->exists());
        $this->actingAs($platform)->get('/organization')->assertOk()->assertSee('Your role: Platform Administrator');
        $this->get('/platform/organizations')->assertOk();
        $this->select('pro-admin@example.test');
        $this->get('/organization')->assertOk()->assertSee('Your role: Administrator')->assertDontSee('Your role: Platform Administrator');
    }
}
