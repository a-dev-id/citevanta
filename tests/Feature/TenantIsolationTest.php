<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiResponse;
use App\Models\ApiUsage;
use App\Models\BatchItem;
use App\Models\Citation;
use App\Models\CostEntry;
use App\Models\Hotel;
use App\Models\HotelAssessment;
use App\Models\HotelMention;
use App\Models\Improvement;
use App\Models\ImprovementEvent;
use App\Models\ImprovementExplanation;
use App\Models\MarketScenario;
use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\CostControl;
use App\Services\OrganizationQuota;
use App\Services\RequestPricing;
use App\Services\TenantBackfill;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use App\Services\VisibilityAnalysis;
use App\Services\VisibilityMetrics;
use Database\Seeders\BatchSetupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantContext $context;

    private int $orgA;

    private int $brandA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->context = app(TenantContext::class);
        $this->owner = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($this->owner);
        $this->owner->forceFill(['is_platform_admin' => false])->save();
        $this->orgA = $this->context->organizationId;
        $this->brandA = $this->context->brandId;
        config(['intelligence.openai_key' => 'mock-only-key', 'intelligence.daily_limit' => 100]);
        Http::preventStrayRequests();
    }

    private function tenant(?User $owner = null, string $name = 'Aurora Coffee'): array
    {
        $owner ??= User::factory()->create();
        $org = Organization::factory()->create(['owner_id' => $owner->id, 'name' => $name.' Organization', 'plan_key' => 'agency', 'monthly_limit_micros' => 100000000]);
        $brand = DB::table('monitored_brands')->insertGetId(['organization_id' => $org->id, 'name' => $name, 'short_name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'official_website' => 'https://aurora.example.test/', 'description' => 'Verified business fixture', 'industry' => 'Food and beverage', 'country' => 'US', 'aliases' => json_encode([$name]), 'verified_at' => now(), 'verified_pages' => '[]', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->context->run($org->id, $brand, function () use ($name) {
            $b = $this->context->brand();
            Hotel::create(['identifier' => $b->slug, 'name' => $name, 'aliases' => [$name], 'official_websites' => [$b->official_website], 'verification_url' => $b->official_website, 'verified_at' => now()]);
            app(BatchSetupSeeder::class)->run();
            SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Coffee shops', 'text' => 'Best independent coffee shops', 'version' => 1, 'active' => true]);
        });

        return [$org, $brand, $owner];
    }

    private function select(int $org, int $brand, ?User $user = null): void
    {
        $user ??= $this->owner;
        $this->context->set($org, $brand, $user);
        $this->actingAs($user)->withSession(['organization_id' => $org, 'monitored_brand_id' => $brand]);
    }

    private function saved(string $text = '1. Nandini Jungle Resort is recommended.'): TestRun
    {
        $run = TestRun::create(['user_id' => $this->context->actorId ?? $this->owner->id, 'ai_provider_id' => AiProvider::first()->id, 'search_prompt_id' => SearchPrompt::first()->id, 'market_scenario_id' => MarketScenario::first()->id, 'submission_id' => (string) Str::uuid(), 'model' => 'gpt-4.1-mini', 'prompt_version' => 1, 'prompt_text' => SearchPrompt::first()->text, 'market_snapshot' => ['name' => 'Australia / English'], 'request_settings' => [], 'status' => 'completed', 'executed_at' => now()]);
        $response = $run->response()->create(['text' => $text, 'status' => 'completed', 'raw_metadata' => ['status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed']]], 'api_response_id' => 'resp_saved_fixture']);
        $response->citations()->create(['url' => $this->context->brand()->official_website, 'domain' => parse_url($this->context->brand()->official_website, PHP_URL_HOST), 'start_index' => 3, 'end_index' => mb_strlen($text), 'title' => 'Official business']);

        return $run;
    }

    private function batch(): TestBatch
    {
        return app(BatchManager::class)->preview(User::findOrFail($this->context->actorId), ['creation_id' => (string) Str::uuid(), 'name' => 'Private batch '.$this->context->organizationId, 'prompt_ids' => [SearchPrompt::first()->id], 'market_ids' => [MarketScenario::where('country', 'AU')->first()->id], 'repetitions' => 1]);
    }

    private function fake(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_mock_tenant', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '1. Aurora Coffee is recommended.', 'annotations' => []]]]], 'usage' => ['input_tokens' => 8528, 'output_tokens' => 512, 'total_tokens' => 9040]])]);
    }

    private function execute(User $user): TestRun
    {
        return app(TestExecutor::class)->execute($user, AiProvider::first(), SearchPrompt::first(), MarketScenario::first(), 'gpt-4.1-mini', (string) Str::uuid());
    }

    private function brandData(string $name = 'Second Brand'): array
    {
        return ['name' => $name, 'official_website' => 'https://second.example.test/', 'description' => 'General business', 'industry' => 'Retail', 'country' => 'US', 'aliases' => $name.' Store', 'verified' => 1, 'active' => 1];
    }

    public function test_platform_admin_creates_organization_with_owner_brand_and_no_api_spending(): void
    {
        $this->owner->forceFill(['is_platform_admin' => true])->save();
        $this->select($this->orgA, $this->brandA);
        $this->post('/organizations', ['organization_name' => 'New Customer', ...$this->brandData()])->assertRedirect('/organization');
        $org = Organization::where('name', 'New Customer')->firstOrFail();
        $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->first();
        $this->assertSame($this->owner->id, $org->owner_id);
        $this->assertSame('owner', $org->members()->first()->pivot->role);
        $this->assertSame('free', $org->plan_key);
        $this->assertSame(0, $org->monthly_limit_micros);
        $this->assertSame($brand->id, session('monitored_brand_id'));
        $this->assertSame(0, TestRun::count());
        $this->get('/organization')->assertOk()->assertSee('New Customer');
        Http::assertNothingSent();
    }

    public function test_owner_can_add_multiple_brands_under_configured_plan(): void
    {
        $this->select($this->orgA, $this->brandA);
        $this->post('/organization/brands', $this->brandData())->assertRedirect();
        $this->assertSame(2, MonitoredBrand::count());
        $other = MonitoredBrand::where('name', 'Second Brand')->firstOrFail();
        $this->post('/brand/switch', ['monitored_brand_id' => $other->id])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Second Brand')->assertDontSee('Best luxury jungle resorts');
        $this->assertSame('Retail', $other->industry);
        Http::assertNothingSent();
    }

    public function test_viewer_can_read_reports_but_cannot_execute_or_modify_any_customer_data(): void
    {
        $run = $this->saved();
        $viewer = User::factory()->create();
        $this->context->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->select($this->orgA, $this->brandA, $viewer);
        foreach (['/dashboard', '/history', '/tests/'.$run->id, '/competitors', '/opportunities', '/improvements', '/organization', '/batches', '/prompts'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach ([['POST', '/tests'], ['POST', '/prompts'], ['POST', '/batches/preview'], ['POST', '/improvements/refresh'], ['POST', '/hotels'], ['PUT', '/organization'], ['POST', '/organization/members'], ['POST', '/organization/brands'], ['POST', '/organization/transfer']] as [$method,$url]) {
            $this->call($method, $url)->assertForbidden();
        }
        $this->get('/tests')->assertForbidden();
        $this->get('/batch-settings')->assertNotFound();
        $this->get('/dashboard')->assertDontSee('Run AI search test');
        Http::assertNothingSent();
    }

    public function test_administrator_manages_brands_and_prompts_but_not_team_limits_or_ownership(): void
    {
        $admin = User::factory()->create();
        $this->context->organization()->members()->attach($admin->id, ['role' => 'administrator']);
        $this->select($this->orgA, $this->brandA, $admin);
        $this->post('/prompts', ['category' => 'Business research', 'text' => 'Best independent stores', 'active' => 1])->assertRedirect();
        $this->post('/organization/brands', $this->brandData())->assertRedirect();
        foreach (['/organization/members', '/organization/transfer', '/organizations'] as $url) {
            $this->post($url, [])->assertForbidden();
        }
        $this->put('/organization', ['name' => 'Hijack', 'monthly_limit' => '100'])->assertForbidden();
        $this->get('/batch-settings')->assertNotFound();
    }

    public function test_organization_switching_requires_membership_and_clears_old_approval_tokens(): void
    {
        [$other,$brand] = $this->tenant($this->owner);
        $this->select($this->orgA, $this->brandA);
        $this->withSession(['test_submissions' => ['old-token'], 'batch_creations' => ['old-batch'], 'improvement_previews' => ['old']])->post('/organization/switch', ['organization_id' => $other->id])->assertRedirect('/dashboard')->assertSessionMissing('test_submissions')->assertSessionMissing('batch_creations')->assertSessionMissing('improvement_previews');
        $this->get('/dashboard')->assertOk()->assertSee('Current Workspace: Aurora Coffee')->assertDontSee('Best luxury jungle resorts');
        $this->assertSame($brand, session('monitored_brand_id'));
        [$outsiderOrg] = $this->tenant();
        $this->post('/organization/switch', ['organization_id' => $outsiderOrg->id])->assertNotFound();
    }

    public function test_forged_session_organization_and_brand_contexts_fail_closed(): void
    {
        [$other,$brand] = $this->tenant();
        $this->actingAs($this->owner)->withSession(['organization_id' => $other->id, 'monitored_brand_id' => $brand])->get('/dashboard')->assertForbidden();
        $this->withSession(['organization_id' => $this->orgA, 'monitored_brand_id' => $brand])->get('/history')->assertForbidden();
        $this->select($this->orgA, $this->brandA);
        $this->post('/brand/switch', ['monitored_brand_id' => $brand])->assertNotFound();
    }

    public function test_direct_ids_for_other_tenant_results_analysis_citations_batches_and_improvements_are_not_found(): void
    {
        [$other,$brand,$owner] = $this->tenant();
        $this->context->set($other->id, $brand, $owner);
        $run = $this->saved('1. Aurora Coffee is recommended.');
        $batch = $this->batch();
        $identity = Hotel::first();
        $improvement = Improvement::factory()->create(['hotel_id' => $identity->id]);
        $citation = $run->response->citations->first();
        $this->select($this->orgA, $this->brandA);
        foreach (['/tests/'.$run->id, '/tests/'.$run->id.'/analysis', '/batches/'.$batch->id, '/improvements/'.$improvement->id, '/prompts/'.$run->search_prompt_id.'/edit'] as $url) {
            $this->get($url)->assertNotFound();
        }
        foreach ([['PUT', '/tests/'.$run->id.'/analysis'], ['PUT', '/tests/'.$run->id.'/citations/'.$citation->id], ['POST', '/batches/'.$batch->id.'/start'], ['POST', '/batches/'.$batch->id.'/cancel'], ['PUT', '/improvements/'.$improvement->id], ['POST', '/improvements/'.$improvement->id.'/explain'], ['PUT', '/hotels/'.$identity->id], ['PUT', '/organization/brands/'.$brand]] as [$method,$url]) {
            $this->call($method, $url)->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_dashboard_history_and_every_scoped_model_exclude_another_tenants_records(): void
    {
        [$other,$brand,$owner] = $this->tenant();
        $this->context->set($other->id, $brand, $owner);
        $foreign = $this->saved('Private Aurora evidence');
        $foreign->usage()->create(['model' => 'gpt-4.1-mini', 'input_tokens' => 999, 'output_tokens' => 99, 'total_tokens' => 1098]);
        $this->select($this->orgA, $this->brandA);
        $local = $this->saved();
        $this->get('/dashboard')->assertOk()->assertDontSee('Private Aurora evidence');
        $this->get('/history')->assertOk()->assertDontSee('Best independent coffee shops');
        foreach ([TestRun::class, AiResponse::class, Citation::class, ApiUsage::class, SearchPrompt::class, MarketScenario::class, Hotel::class, Improvement::class, BatchItem::class, CostEntry::class] as $model) {
            $this->assertFalse($model::query()->where('organization_id', $other->id)->exists());
        }
        $this->assertSame(1, app(VisibilityMetrics::class)->summary(app(VisibilityMetrics::class)->runs())['total']);
    }

    public function test_two_brands_in_one_organization_have_separate_prompts_and_evidence(): void
    {
        $this->select($this->orgA, $this->brandA);
        $local = $this->saved();
        $brand = MonitoredBrand::factory()->create(['name' => 'Independent Bookshop', 'short_name' => 'Independent Bookshop']);
        $this->post('/brand/switch', ['monitored_brand_id' => $brand->id])->assertRedirect();
        $this->get('/history')->assertOk()->assertDontSee('Best luxury jungle resorts');
        $this->get('/tests/'.$local->id)->assertNotFound();
        $this->context->set($this->orgA, $brand->id, $this->owner);
        $this->assertSame(0, TestRun::count());
        $this->assertSame(0, SearchPrompt::count());
        $this->assertSame('Independent Bookshop', Hotel::first()->name);
    }

    public function test_foreign_prompt_market_and_identity_ids_are_rejected_before_search_or_correction(): void
    {
        [$other,$brand,$owner] = $this->tenant();
        $this->context->set($other->id, $brand, $owner);
        $prompt = SearchPrompt::first();
        $market = MarketScenario::first();
        $identity = Hotel::first();
        $this->select($this->orgA, $this->brandA);
        $run = $this->saved();
        $this->get('/tests');
        $token = last(session('test_submissions'));
        $this->post('/tests', ['provider_id' => AiProvider::first()->id, 'prompt_id' => $prompt->id, 'market_id' => $market->id, 'model' => 'gpt-4.1-mini', 'submission_id' => $token])->assertSessionHasErrors(['prompt_id', 'market_id']);
        $this->put('/tests/'.$run->id.'/analysis', ['hotel_id' => $identity->id, 'decision' => 'not_mentioned', 'review_note' => 'Reviewed identity'])->assertSessionHasErrors('hotel_id');
        Http::assertNothingSent();
    }

    public function test_model_mass_assignment_cannot_reassign_ownership_and_foreign_parents_fail(): void
    {
        [$other,$brand,$owner] = $this->tenant();
        $this->context->set($other->id, $brand, $owner);
        $foreign = $this->saved();
        $this->select($this->orgA, $this->brandA);
        $run = $this->saved();
        $run->fill(['organization_id' => $other->id, 'monitored_brand_id' => $brand]);
        $run->save();
        $this->assertSame($this->orgA, $run->fresh()->organization_id);
        try {
            AiResponse::create(['text' => 'bad', 'status' => 'completed', 'test_run_id' => $foreign->id]);
            $this->fail('Foreign parent accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('test_run_id', $e->errors());
        }
    }

    public function test_database_composite_foreign_keys_reject_raw_cross_tenant_parent_links(): void
    {
        [$other,$brand,$owner] = $this->tenant();
        $this->context->set($other->id, $brand, $owner);
        $foreign = $this->saved();
        $this->select($this->orgA, $this->brandA);
        $local = $this->saved();
        $this->expectException(QueryException::class);
        DB::table('ai_responses')->where('id', $local->response->id)->update(['test_run_id' => $foreign->id]);
    }

    public function test_missing_context_returns_no_customer_records_and_blocks_creation(): void
    {
        $this->saved();
        $this->context->clear();
        $this->assertSame(0, TestRun::count());
        $this->assertSame(0, Hotel::count());
        $this->assertSame(0, SearchPrompt::count());
        try {
            SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Bad', 'text' => 'No context', 'version' => 1, 'active' => true]);
            $this->fail('Missing tenant accepted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_owner_team_management_transfer_and_revocation_are_enforced(): void
    {
        $this->select($this->orgA, $this->brandA);
        $this->post('/organization/members', ['name' => 'Team Viewer', 'email' => 'team@example.test', 'password' => 'TemporaryPassword123!', 'role' => 'viewer'])->assertRedirect();
        $member = User::where('email', 'team@example.test')->firstOrFail();
        $this->put('/organization/members/'.$member->id, ['role' => 'administrator'])->assertRedirect();
        $this->assertSame('administrator', $this->context->organization()->members()->where('users.id', $member->id)->first()->pivot->role);
        $this->post('/organization/transfer', ['owner_id' => $member->id])->assertRedirect();
        $this->assertSame($member->id, $this->context->organization()->owner_id);
        $this->post('/organization/transfer', ['owner_id' => $this->owner->id])->assertForbidden();
        $this->select($this->orgA, $this->brandA, $member);
        $this->put('/organization/members/'.$this->owner->id, ['role' => 'remove'])->assertRedirect();
        $this->actingAs($this->owner)->get('/history')->assertForbidden();
    }

    public function test_team_assignments_cannot_grant_platform_privileges_or_arbitrary_owner_role(): void
    {
        $this->select($this->orgA, $this->brandA);
        $this->post('/organization/members', ['name' => 'Malicious', 'email' => 'bad@example.test', 'password' => 'TemporaryPassword123!', 'role' => 'owner', 'is_platform_admin' => true])->assertSessionHasErrors('role');
        $outsider = User::factory()->create();
        $this->put('/organization/members/'.$outsider->id, ['role' => 'administrator'])->assertNotFound();
        $this->post('/organization/transfer', ['owner_id' => $outsider->id])->assertNotFound();
        $this->put('/organization/members/'.$this->owner->id, ['role' => 'remove'])->assertStatus(422);
    }

    public function test_free_brand_and_team_limits_are_enforced_on_the_server(): void
    {
        $this->context->organization()->forceFill(['plan_key' => 'free'])->save();
        $this->select($this->orgA, $this->brandA);
        $this->post('/organization/brands', $this->brandData())->assertSessionHasErrors('quota');
        foreach (['one', 'two'] as $name) {
            $this->post('/organization/members', ['name' => $name, 'email' => $name.'@example.test', 'password' => 'TemporaryPassword123!', 'role' => 'viewer'])->assertSessionHasErrors('quota');
        }
        $this->post('/organization/members', ['name' => 'three', 'email' => 'three@example.test', 'password' => 'TemporaryPassword123!', 'role' => 'viewer'])->assertSessionHasErrors('quota');
        $this->assertSame(1, $this->context->organization()->members()->count());
    }

    public function test_plan_batch_size_repetitions_and_provider_limits_cannot_be_bypassed(): void
    {
        $this->context->organization()->forceFill(['plan_key' => 'free'])->save();
        try {
            app(OrganizationQuota::class)->assertBatch(11, 1);
            $this->fail('Batch quota accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }
        try {
            app(OrganizationQuota::class)->assertBatch(1, 2);
            $this->fail('Repetitions accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }
        config(['plans.definitions.free.providers' => []]);
        $this->fake();
        try {
            $this->execute($this->owner);
            $this->fail('Provider restriction ignored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }Http::assertNothingSent();
    }

    public function test_organization_monthly_request_quota_is_independent_of_other_organizations(): void
    {
        config(['plans.definitions.agency.monthly_searches' => 1]);
        $this->fake();
        $this->execute($this->owner);
        try {
            $this->execute($this->owner);
            $this->fail('Monthly quota exceeded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credits', $e->errors());
        }
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $other = $this->execute($owner);
        $this->assertSame($org->id, $other->organization_id);
        Http::assertSentCount(2);
    }

    public function test_global_zero_budget_is_preserved_even_with_large_organization_allowances(): void
    {
        SpendingSetting::first()->update(['monthly_limit_micros' => 0]);
        $this->fake();
        try {
            $this->execute($this->owner);
            $this->fail('Global zero limit ignored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cost', $e->errors());
        }
        $this->assertSame(0, CostEntry::count());
        Http::assertNothingSent();
    }

    public function test_zero_organization_allowance_blocks_requests_even_when_global_budget_is_available(): void
    {
        $this->context->organization()->forceFill(['monthly_limit_micros' => 0])->save();
        $this->fake();
        try {
            $this->execute($this->owner);
            $this->fail('Organization zero limit ignored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }Http::assertNothingSent();
    }

    public function test_usage_tokens_costs_and_counts_are_independent_by_organization(): void
    {
        $this->fake();
        $first = $this->execute($this->owner);
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $this->execute($owner);
        $this->execute($owner);
        $this->select($this->orgA, $this->brandA);
        $this->get('/organization')->assertOk()->assertDontSee('Recorded input')->assertDontSee('Known API usage estimate');
        $this->assertSame(1, TestRun::count());
        $this->assertSame(8528, $first->usage->input_tokens);
        $this->assertSame($first->cost->committed_micros, app(OrganizationQuota::class)->committed());
        $this->assertSame($first->cost->committed_micros * 3, app(CostControl::class)->balance()['committed']);
        Http::assertSentCount(3);
    }

    public function test_cron_dispatch_restores_each_tenant_context_and_persists_correct_ownership(): void
    {
        $first = $this->batch();
        app(BatchManager::class)->start($first);
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $second = $this->batch();
        app(BatchManager::class)->start($second);
        $this->context->clear();
        $this->fake();
        $this->assertSame(2, app(BatchProcessor::class)->process(2));
        $this->assertNull($this->context->organizationId);
        foreach ([[$this->orgA, $this->brandA], [$org->id, $brand]] as [$organization,$brandId]) {
            $this->context->set($organization, $brandId);
            $this->assertSame(1, TestRun::count());
            $this->assertSame(1, CostEntry::count());
            $this->assertSame(1, ApiUsage::count());
            $this->assertSame($organization, TestRun::first()->response->organization_id);
        }
        Http::assertSentCount(2);
    }

    public function test_cron_does_not_execute_work_after_creator_membership_is_revoked(): void
    {
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $batch = $this->batch();
        app(BatchManager::class)->start($batch);
        $org->members()->detach($owner->id);
        $this->context->clear();
        $this->fake();
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame('paused', DB::table('test_batches')->where('id', $batch->id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_brand_identity_analysis_works_for_non_hotel_businesses(): void
    {
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $run = $this->saved('1. Aurora Coffee is recommended.');
        app(VisibilityAnalysis::class)->analyze($run->response);
        $summary = app(VisibilityMetrics::class)->summary(app(VisibilityMetrics::class)->runs());
        $this->assertSame(1, $summary['mentioned']);
        $this->assertSame(1, $summary['recommended']);
        $this->assertSame(1, $summary['cited']);
        $this->assertTrue(Hotel::first()->isMonitoredBrand());
    }

    public function test_backfill_is_repeatable_preserves_ids_original_data_and_revoked_memberships(): void
    {
        $run = $this->saved();
        app(VisibilityAnalysis::class)->analyze($run->response);
        $original = $run->response->fresh()->only(['text', 'raw_metadata', 'api_response_id']);
        $id = $run->id;
        $before = [];
        foreach (array_keys(TenantBackfill::PARENTS) as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $this->assertSame($before, app(TenantBackfill::class)->run());
        $this->assertSame($before, app(TenantBackfill::class)->run());
        $this->assertSame($original, $run->response->fresh()->only(['text', 'raw_metadata', 'api_response_id']));
        $this->assertSame($id, $run->fresh()->id);
        foreach (array_keys(TenantBackfill::PARENTS) as $table) {
            $this->assertFalse(DB::table($table)->whereNull('organization_id')->orWhereNull('monitored_brand_id')->exists());
        }
        $this->context->organization()->members()->detach($this->owner->id);
        app(TenantBackfill::class)->run();
        $this->assertFalse(DB::table('organization_user')->where('user_id', $this->owner->id)->exists());
    }

    public function test_policies_reject_foreign_records_and_viewer_updates(): void
    {
        $run = $this->saved();
        $viewer = User::factory()->create();
        $this->context->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $run));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $run));
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $run));
    }

    public function test_only_platform_administrators_can_change_plan_or_global_cost_controls(): void
    {
        [$org,$brand,$owner] = $this->tenant();
        $this->select($org->id, $brand, $owner);
        $this->put('/organization', ['name' => $org->name, 'monthly_limit' => '10', 'plan_key' => 'pro'])->assertForbidden();
        $this->put('/batch-settings', ['monthly_limit' => '100', 'batch_request_limit' => 20, 'safety_percent' => 25])->assertNotFound();
        $this->get('/organizations/create')->assertForbidden();
        $this->assertSame('agency', $org->fresh()->plan_key);
    }

    public function test_monthly_request_quota_is_shared_across_brands_in_the_same_organization(): void
    {
        config(['plans.definitions.agency.monthly_searches' => 1]);
        $this->fake();
        $this->execute($this->owner);
        $brand = MonitoredBrand::factory()->create(['name' => 'Second Retail Business']);
        $this->context->set($this->orgA, $brand->id, $this->owner);
        SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Retail', 'text' => 'Best retail businesses', 'version' => 1, 'active' => true]);
        try {
            $this->execute($this->owner);
            $this->fail('A second brand bypassed the organization quota.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credits', $e->errors());
        }
        $this->assertSame(1, DB::table('test_runs')->where('organization_id', $this->orgA)->count());
        Http::assertSentCount(1);
    }

    public function test_organization_cost_reservations_are_shared_across_brands(): void
    {
        $this->fake();
        $first = $this->execute($this->owner);
        $quote = app(RequestPricing::class)->quote('Best retail businesses', 'gpt-4.1-mini', 25);
        $this->context->organization()->forceFill(['monthly_limit_micros' => $first->cost->committed_micros + $quote['reserve'] - 1])->save();
        $brand = MonitoredBrand::factory()->create();
        $this->context->set($this->orgA, $brand->id, $this->owner);
        SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Retail', 'text' => 'Best retail businesses', 'version' => 1, 'active' => true]);
        try {
            $this->execute($this->owner);
            $this->fail('Another brand bypassed the spending reserve.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }Http::assertSentCount(1);
    }

    public function test_brands_can_be_disabled_and_reactivated_without_losing_evidence(): void
    {
        $this->select($this->orgA, $this->brandA);
        $brand = MonitoredBrand::factory()->create(['name' => 'Second Brand']);
        $this->put('/organization/brands/'.$brand->id, [...$this->brandData(), 'active' => 0])->assertRedirect();
        $this->assertFalse($brand->fresh()->active);
        $this->put('/organization/brands/'.$brand->id, [...$this->brandData(), 'active' => 1])->assertRedirect();
        $this->assertTrue($brand->fresh()->active);
        $this->put('/organization/brands/'.$this->brandA, [...$this->brandData('Nandini Jungle by Hanging Gardens'), 'active' => 0])->assertRedirect();
        $this->put('/organization/brands/'.$brand->id, [...$this->brandData(), 'active' => 0])->assertSessionHasErrors('active');
    }

    public function test_inactive_selected_brand_falls_back_only_within_the_authorized_organization(): void
    {
        $brand = MonitoredBrand::factory()->create();
        $this->select($this->orgA, $brand->id);
        DB::table('monitored_brands')->where('id', $brand->id)->update(['active' => false]);
        $this->get('/dashboard')->assertOk()->assertSessionHas('monitored_brand_id', $this->brandA);
    }

    public function test_viewer_login_uses_membership_and_logout_still_works_after_revocation(): void
    {
        $viewer = User::factory()->create();
        $this->context->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->post('/login', ['email' => $viewer->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->context->organization()->members()->detach($viewer->id);
        $this->get('/history')->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['email' => $viewer->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_reservation_revalidates_creator_role_inside_the_budget_transaction(): void
    {
        $viewer = User::factory()->create();
        $this->context->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        try {
            app(CostControl::class)->reserve(['user_id' => $viewer->id, 'model' => 'gpt-4.1-mini', 'prompt_text' => 'A controlled search', 'submission_id' => (string) Str::uuid()]);
            $this->fail('A viewer reserved a paid request.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }
        $this->assertSame(0, TestRun::count());
        $this->assertSame(0, CostEntry::count());
        Http::assertNothingSent();
    }

    public function test_circular_batch_result_reference_cannot_point_to_another_tenant(): void
    {
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $foreign = $this->saved('Aurora Coffee');
        $this->context->set($this->orgA, $this->brandA, $this->owner);
        $batch = $this->batch();
        $this->expectException(QueryException::class);
        DB::table('batch_items')->where('id', $batch->items->first()->id)->update(['test_run_id' => $foreign->id]);
    }

    public function test_private_evidence_children_and_explanation_history_are_all_scoped(): void
    {
        [$org,$brand,$owner] = $this->tenant();
        $this->context->set($org->id, $brand, $owner);
        $run = $this->saved('1. Aurora Coffee is recommended.');
        app(VisibilityAnalysis::class)->analyze($run->response);
        $run->response->mentions()->create(['hotel_name' => 'Aurora Coffee', 'normalized_identifier' => 'aurora', 'position' => 3, 'is_nandini' => true, 'is_recommendation' => true, 'matching_rule' => 'fixture']);
        $record = Improvement::factory()->create();
        $event = $record->events()->create(['user_id' => $owner->id, 'to_status' => 'new', 'note' => 'Private review']);
        $explanation = $record->explanations()->create(['user_id' => $owner->id, 'ai_provider_id' => AiProvider::first()->id, 'submission_id' => (string) Str::uuid(), 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'evidence_snapshot' => ['test_id' => $run->id], 'request_settings' => [], 'text' => 'Private explanation']);
        $this->select($this->orgA, $this->brandA);
        foreach ([HotelMention::class, HotelAssessment::class, ImprovementEvent::class, ImprovementExplanation::class] as $model) {
            $this->assertSame(0, $model::where('organization_id', $org->id)->count());
        }
        $this->get('/improvements')->assertDontSee('Private explanation')->assertDontSee('Private review');
    }

    public function test_invalid_plan_configuration_fails_closed_and_public_signup_and_billing_remain_absent(): void
    {
        config(['plans.definitions.agency.monthly_searches' => 'unlimited']);
        try {
            app(OrganizationQuota::class)->plan();
            $this->fail('Malformed quotas accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quota', $e->errors());
        }
        foreach (['/register', '/billing', '/subscribe'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->get('/storage/app/private/administrator-credentials.txt')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_legacy_schema_backfill_preserves_existing_ids_raw_responses_and_manual_corrections(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.tenant_migration' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
        DB::purge('tenant_migration');
        DB::setDefaultConnection('tenant_migration');
        Schema::clearResolvedInstance('db.schema');
        app()->forgetInstance('db.schema');
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                if (basename($file) >= '2026_11_10_060422_add_organization_foundation.php') {
                    continue;
                }(require $file)->up();
            }
            $now = now()->toDateTimeString();
            DB::table('users')->insert(['id' => 5, 'name' => 'Original Owner', 'email' => 'original@example.test', 'password' => 'fixture-hash', 'is_admin' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('ai_providers')->insert(['id' => 11, 'name' => 'OpenAI', 'identifier' => 'openai', 'enabled' => true, 'model_configuration' => '{}']);
            DB::table('hotels')->insert(['id' => 17, 'identifier' => 'nandini', 'name' => 'Nandini Jungle by Hanging Gardens', 'aliases' => '["Nandini Jungle Resort"]', 'official_websites' => '["https://nandinibali.com/"]', 'verification_url' => 'https://nandinibali.com/', 'verified_at' => $now]);
            DB::table('search_prompts')->insert(['id' => 23, 'prompt_id' => (string) Str::uuid(), 'category' => 'Luxury', 'text' => 'Original search prompt', 'version' => 2, 'active' => true]);
            DB::table('market_scenarios')->insert(['id' => 29, 'name' => 'Australia / English', 'country' => 'AU', 'language' => 'English', 'search_settings' => '{"type":"approximate","country":"AU"}']);
            DB::table('test_runs')->insert(['id' => 31, 'user_id' => 5, 'ai_provider_id' => 11, 'search_prompt_id' => 23, 'market_scenario_id' => 29, 'submission_id' => (string) Str::uuid(), 'model' => 'gpt-4.1-mini', 'prompt_version' => 2, 'prompt_text' => 'Original search prompt', 'market_snapshot' => '{"name":"Australia / English"}', 'request_settings' => '{}', 'status' => 'completed', 'repetition' => 1, 'executed_at' => $now]);
            DB::table('ai_responses')->insert(['id' => 37, 'test_run_id' => 31, 'text' => 'Original AI answer — café', 'raw_metadata' => '{"id":"resp_original","output":[]}', 'api_response_id' => 'resp_original', 'status' => 'completed']);
            DB::table('hotel_assessments')->insert(['id' => 41, 'ai_response_id' => 37, 'hotel_id' => 17, 'mentioned' => true, 'recommended' => true, 'rank' => 1, 'evidence' => 'Original evidence', 'occurrences' => '[]', 'supporting_citation_ids' => '[43]', 'rule' => 'manual', 'manual' => true, 'reviewed_by' => 5, 'review_note' => 'Preserve this manual correction']);
            DB::table('citations')->insert(['id' => 43, 'ai_response_id' => 37, 'url' => 'https://nandinibali.com/', 'title' => 'Original citation', 'domain' => 'nandinibali.com', 'is_official' => true, 'start_index' => 0, 'end_index' => 8, 'hotel_id' => 17, 'classification' => 'nandini_official', 'manual' => true, 'reviewed_by' => 5, 'review_note' => 'Preserve citation correction']);
            DB::table('hotel_mentions')->insert(['id' => 47, 'ai_response_id' => 37, 'hotel_name' => 'Nandini', 'normalized_identifier' => 'nandini', 'position' => 0, 'is_nandini' => true, 'is_recommendation' => true, 'matching_rule' => 'original']);
            DB::table('api_usages')->insert(['id' => 53, 'test_run_id' => 31, 'model' => 'gpt-4.1-mini', 'input_tokens' => 8528, 'output_tokens' => 512, 'total_tokens' => 9040]);
            DB::table('improvements')->insert(['id' => 59, 'hotel_id' => 17, 'detection_key' => 'original', 'title' => 'Original recommendation', 'category' => 'Website content improvements', 'priority' => 'low', 'confidence' => 'low', 'explanation' => 'Hypothesis', 'evidence' => '[{"test_id":31}]', 'actions' => '{"review":"Review verified evidence"}', 'notes' => 'Preserve notes', 'status' => 'completed', 'active' => true, 'completed_at' => $now, 'refreshed_at' => $now]);
            DB::table('improvement_events')->insert(['id' => 61, 'improvement_id' => 59, 'user_id' => 5, 'from_status' => 'new', 'to_status' => 'completed', 'note' => 'Original review history']);
            DB::table('test_batches')->insert(['id' => 67, 'user_id' => 5, 'creation_id' => (string) Str::uuid(), 'name' => 'Original batch', 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'ai_provider_id' => 11, 'configuration' => '{"repetitions":1}', 'request_count' => 1, 'request_limit' => 20]);
            DB::table('batch_items')->insert(['id' => 71, 'test_batch_id' => 67, 'search_prompt_id' => 23, 'market_scenario_id' => 29, 'repetition' => 1, 'prompt_snapshot' => '{}', 'market_snapshot' => '{}', 'status' => 'completed', 'attempts' => 1, 'test_run_id' => 31]);
            DB::table('test_runs')->where('id', 31)->update(['test_batch_id' => 67, 'batch_item_id' => 71]);
            DB::table('budget_months')->insert(['id' => 79, 'month' => now('UTC')->format('Y-m'), 'opening_micros' => 0, 'opening_through_id' => 0]);
            DB::table('cost_entries')->insert(['id' => 73, 'test_run_id' => 31, 'budget_month_id' => 79, 'reserved_micros' => 81500, 'committed_micros' => 81500, 'safety_percent' => 25, 'status' => 'uncertain', 'pricing_snapshot' => '{}']);
            DB::table('improvement_explanations')->insert(['id' => 83, 'improvement_id' => 59, 'user_id' => 5, 'ai_provider_id' => 11, 'submission_id' => (string) Str::uuid(), 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'evidence_snapshot' => '{"test_id":31}', 'request_settings' => '{}', 'usage' => '{"input_tokens":100}', 'text' => 'Original constrained explanation']);
            DB::table('cost_entries')->insert(['id' => 89, 'improvement_explanation_id' => 83, 'budget_month_id' => 79, 'reserved_micros' => 66400, 'committed_micros' => 66400, 'safety_percent' => 25, 'status' => 'uncertain', 'pricing_snapshot' => '{}']);
            $before = [];
            foreach (array_merge(array_keys(TenantBackfill::PARENTS), ['users', 'budget_months']) as $table) {
                $columns = Schema::getColumnListing($table);
                $before[$table] = [$columns, DB::table($table)->orderBy('id')->get($columns)->toJson()];
            }
            (require database_path('migrations/2026_11_10_060422_add_organization_foundation.php'))->up();
            (require database_path('migrations/2026_11_10_060423_enforce_tenant_ownership.php'))->up();
            foreach ($before as $table => [$columns,$rows]) {
                $this->assertSame($rows, DB::table($table)->orderBy('id')->get($columns)->toJson(), $table.' original data changed');
            }
            $organization = DB::table('organizations')->where('slug', 'nandini-jungle')->first();
            $brand = DB::table('monitored_brands')->where('organization_id', $organization->id)->first();
            $this->assertSame(5, $organization->owner_id);
            $this->assertSame('owner', DB::table('organization_user')->where('organization_id', $organization->id)->value('role'));
            foreach (array_keys(TenantBackfill::PARENTS) as $table) {
                $this->assertFalse(DB::table($table)->where('organization_id', '!=', $organization->id)->orWhere('monitored_brand_id', '!=', $brand->id)->exists());
            }
            app(TenantBackfill::class)->run();
            $this->assertSame('Preserve this manual correction', DB::table('hotel_assessments')->where('id', 41)->value('review_note'));
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('tenant_migration');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
        }
    }

    public function test_revoked_old_batch_does_not_starve_an_authorized_batch_in_the_same_brand(): void
    {
        [$org,$brand,$oldOwner] = $this->tenant();
        $this->context->set($org->id, $brand, $oldOwner);
        $old = $this->batch();
        app(BatchManager::class)->start($old);
        $newOwner = User::factory()->create();
        $org->members()->attach($newOwner->id, ['role' => 'owner']);
        $org->forceFill(['owner_id' => $newOwner->id])->save();
        $org->members()->detach($oldOwner->id);
        $this->context->set($org->id, $brand, $newOwner);
        $new = $this->batch();
        app(BatchManager::class)->start($new);
        $this->context->clear();
        $this->fake();
        $this->assertSame(1, app(BatchProcessor::class)->process(1));
        $this->assertSame('paused', DB::table('test_batches')->where('id', $old->id)->value('status'));
        $this->assertSame($newOwner->id, DB::table('test_runs')->where('test_batch_id', $new->id)->value('user_id'));
        Http::assertSentCount(1);
    }
}
