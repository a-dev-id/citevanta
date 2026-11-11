<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\CreditEntry;
use App\Models\MarketScenario;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\SimulatedProvider;
use App\Services\BatchManager;
use App\Services\CreditLedger;
use App\Services\PilotStudy;
use App\Services\SimulatedMonitoring;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use App\Services\VisibilityMetrics;
use Database\Seeders\LocalTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SimulatedMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['local_testing.credentials_path' => storage_path('framework/simulation-test-credentials-'.getmypid().'.json'), 'local_testing.simulated_execution' => true, 'spending.execution_enabled' => false, 'plans.request_rate_per_minute.free' => 100]);
        $this->seed(LocalTestingSeeder::class);
        Http::preventStrayRequests();
        $this->mock(OpenAiProvider::class)->shouldNotReceive('execute');
        $this->select('starter');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        File::delete(config('local_testing.credentials_path'));
        parent::tearDown();
    }

    private function select(string $plan, string $suffix = ''): void
    {
        $this->owner = User::where('email', $plan.$suffix.'@example.test')->firstOrFail();
        $org = $this->owner->organizations()->firstOrFail();
        $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->first();
        app(TenantContext::class)->set($org->id, $brand->id, $this->owner);
        $this->actingAs($this->owner)->withSession(['organization_id' => $org->id, 'monitored_brand_id' => $brand->id]);
    }

    private function campaign(int $count = 12): TestBatch
    {
        return app(BatchManager::class)->preview($this->owner, ['creation_id' => (string) Str::uuid(), 'name' => 'Workflow test', 'prompt_ids' => SearchPrompt::limit($count)->pluck('id')->all(), 'market_ids' => [MarketScenario::firstOrFail()->id], 'repetitions' => 1]);
    }

    private function quick(?string $submission = null): TestRun
    {
        return app(TestExecutor::class)->execute($this->owner, AiProvider::where('identifier', 'simulated')->firstOrFail(), SearchPrompt::firstOrFail(), MarketScenario::firstOrFail(), SimulatedProvider::MODEL, $submission ?? (string) Str::uuid());
    }

    public function test_all_paid_plans_complete_twelve_search_campaigns_with_only_simulated_credits(): void
    {
        foreach (['starter' => 450, 'pro' => 1500, 'agency' => 4000] as $plan => $allowance) {
            $this->select($plan);
            $batch = $this->campaign();
            $this->get('/batches/'.$batch->id)->assertOk()->assertSee('Start Campaign')->assertDontSee('@endif')->assertDontSee('cron');
            $this->post('/batches/'.$batch->id.'/start', ['confirm_credits' => 1, 'large_warning' => 1])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame(12, app(CreditLedger::class)->balance()['reserved']);
            for ($i = 0; $i < 4; $i++) {
                $this->post('/batches/'.$batch->id.'/process')->assertSessionHasNoErrors()->assertRedirect();
            }
            $this->assertSame('completed', $batch->fresh()->status);
            $this->assertSame(12, $batch->runs()->where('is_simulated', true)->where('ai_provider_id', $batch->ai_provider_id)->count());
            $balance = app(CreditLedger::class)->balance();
            $this->assertSame(12, $balance['used']);
            $this->assertSame(0, $balance['reserved']);
            $this->assertSame($allowance - 12, $balance['remaining']);
            $this->assertCount(22, app(VisibilityMetrics::class)->runs());
            $this->get('/dashboard')->assertOk()->assertSee('SIMULATED TEST DATA');
            $this->get('/competitors')->assertOk();
        }
        $this->assertSame(0, DB::table('api_usages')->count());
        $this->assertSame(0, DB::table('cost_entries')->count());
    }

    public function test_free_can_run_five_quick_tests_and_sixth_is_rejected_without_campaign_access(): void
    {
        $this->select('free');
        foreach (range(1, 5) as $n) {
            $this->assertSame('completed', $this->quick()->status);
        }
        $this->assertSame(0, app(CreditLedger::class)->balance()['remaining']);
        $this->get('/batches/create')->assertForbidden();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Insufficient monthly');
        $this->quick();
    }

    public function test_quick_test_http_flow_uses_the_separate_provider_and_saves_labelled_results(): void
    {
        $this->get('/tests')->assertOk()->assertSee(SimulatedProvider::MODEL);
        $submission = (string) Str::uuid();
        $data = ['provider_id' => AiProvider::where('identifier', 'simulated')->firstOrFail()->id, 'prompt_id' => SearchPrompt::firstOrFail()->id, 'market_id' => MarketScenario::firstOrFail()->id, 'model' => SimulatedProvider::MODEL, 'submission_id' => $submission];
        $this->withSession(['test_submissions' => [$submission]])->post('/tests', $data)->assertSessionHasNoErrors()->assertRedirect();
        $run = TestRun::where('submission_id', $submission)->firstOrFail();
        $this->assertTrue($run->is_simulated);
        $this->get('/tests/'.$run->id)->assertOk()->assertSee('SIMULATED TEST RESULT');
        $this->assertSame(1, app(CreditLedger::class)->balance()['used']);
    }

    public function test_cancellation_releases_unused_credits_without_refunding_completed_searches(): void
    {
        $batch = $this->campaign();
        app(BatchManager::class)->start($batch);
        $this->assertSame(3, app(SimulatedMonitoring::class)->process($batch));
        $this->post('/batches/'.$batch->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertSame(9, $batch->items()->where('status', 'cancelled')->count());
        $balance = app(CreditLedger::class)->balance();
        $this->assertSame(3, $balance['used']);
        $this->assertSame(0, $balance['reserved']);
        $this->assertSame(0, app(SimulatedMonitoring::class)->process($batch));
    }

    public function test_duplicate_submissions_and_approval_retries_do_not_charge_twice(): void
    {
        $submission = (string) Str::uuid();
        $run = $this->quick($submission);
        $this->assertSame($run->id, $this->quick($submission)->id);
        $batch = $this->campaign(1);
        app(BatchManager::class)->start($batch);
        app(BatchManager::class)->start($batch);
        $this->assertSame(1, app(CreditLedger::class)->balance()['reserved']);
        app(SimulatedMonitoring::class)->process($batch);
        app(SimulatedMonitoring::class)->process($batch);
        $this->assertSame(2, app(CreditLedger::class)->balance()['used']);
    }

    public function test_existing_unexecuted_openai_draft_is_converted_only_on_explicit_start(): void
    {
        config(['local_testing.simulated_execution' => false]);
        $batch = $this->campaign();
        $this->assertSame('openai', $batch->provider->identifier);
        config(['local_testing.simulated_execution' => true]);
        $this->get('/batches/'.$batch->id)->assertOk()->assertSee('Start Campaign');
        $this->assertSame('openai', $batch->fresh()->provider->identifier);
        app(BatchManager::class)->start($batch);
        $this->assertSame('simulated', $batch->fresh()->provider->identifier);
        $this->assertSame(SimulatedProvider::MODEL, $batch->fresh()->model);
        $this->assertSame(12, app(CreditLedger::class)->balance()['reserved']);
    }

    public function test_failed_simulations_release_their_reservation_and_do_not_create_financial_entries(): void
    {
        $this->mock(SimulatedProvider::class)->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Fixture error'));
        $run = $this->quick();
        $this->assertSame('failed', $run->status);
        $this->assertSame('released', CreditEntry::where('test_run_id', $run->id)->firstOrFail()->status);
        $this->assertSame(450, app(CreditLedger::class)->balance()['remaining']);
    }

    public function test_quota_prevents_oversubscribed_campaign_reservations_atomically(): void
    {
        $batch = $this->campaign();
        app(CreditLedger::class)->period()->update(['allowance' => 5]);
        try {
            app(BatchManager::class)->start($batch);
            $this->fail('Insufficient credits must block approval');
        } catch (ValidationException) {
            $this->assertSame(0, CreditEntry::where('organization_id', $batch->organization_id)->count());
            $this->assertSame('draft', $batch->fresh()->status);
        }
    }

    public function test_production_nonfixture_and_disabled_config_cannot_use_the_simulator(): void
    {
        $this->app->instance('env', 'production');
        $this->assertFalse(app(SimulatedMonitoring::class)->available());
        $this->app->instance('env', 'testing');
        config(['local_testing.simulated_execution' => false]);
        $this->assertFalse(app(SimulatedMonitoring::class)->available());
        config(['local_testing.simulated_execution' => true]);
        $original = Organization::findOrFail(1);
        $user = User::factory()->create();
        $original->members()->attach($user->id, ['role' => 'owner']);
        $brand = DB::table('monitored_brands')->where('organization_id', 1)->first();
        app(TenantContext::class)->set(1, $brand->id, $user);
        $this->assertFalse(app(SimulatedMonitoring::class)->available());
        $this->expectException(ValidationException::class);
        app(SimulatedProvider::class)->execute([]);
    }

    public function test_viewers_and_cross_organization_requests_cannot_start_process_or_cancel(): void
    {
        $batch = $this->campaign();
        $this->select('pro', '-viewer');
        foreach (['start', 'process', 'cancel'] as $action) {
            $this->post('/batches/'.$batch->id.'/'.$action, [])->assertNotFound();
        }
        $this->select('starter', '-admin');
        app(BatchManager::class)->start($batch);
        $this->assertSame(3, app(SimulatedMonitoring::class)->process($batch));
        $this->select('pro');
        $foreign = $this->campaign(1);
        $this->select('pro', '-viewer');
        foreach (['start', 'process', 'cancel'] as $action) {
            $this->post('/batches/'.$foreign->id.'/'.$action, [])->assertForbidden();
        }
    }

    public function test_monthly_reset_does_not_roll_over_simulated_credits_or_alter_original_ledgers(): void
    {
        $this->quick();
        $old = app(CreditLedger::class)->period();
        $this->travelTo($old->ends_at->addSecond());
        $this->assertSame(450, app(CreditLedger::class)->balance()['remaining']);
        $this->assertSame(1, CreditEntry::where('credit_period_id', $old->id)->where('status', 'used')->count());
        $this->assertSame(0, DB::table('credit_entries')->where('organization_id', 1)->count());
    }

    public function test_related_pages_do_not_render_literal_blade_directives(): void
    {
        $original = Organization::findOrFail(1);
        $user = User::factory()->create();
        $original->members()->attach($user->id, ['role' => 'owner']);
        $original->forceFill(['owner_id' => $user->id])->save();
        $brand = DB::table('monitored_brands')->where('organization_id', 1)->first();
        app(TenantContext::class)->set(1, $brand->id, $user);
        $this->actingAs($user)->withSession(['organization_id' => 1, 'monitored_brand_id' => $brand->id]);
        $batch = app(PilotStudy::class)->prepare($user);
        foreach (['/pilot', '/batches', '/batches/'.$batch->id, '/tests', '/history', '/settings', '/organization', '/improvements'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertDoesNotMatchRegularExpression('/@(endif|endforeach|else|endsection)\\b/', strip_tags($response->getContent()), $url);
        }
    }
}
