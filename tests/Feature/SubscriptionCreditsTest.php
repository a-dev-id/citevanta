<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\CreditEntry;
use App\Models\CreditPeriod;
use App\Models\MarketScenario;
use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\PlanChange;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\CostControl;
use App\Services\CreditLedger;
use App\Services\OrganizationQuota;
use App\Services\PilotStudy;
use App\Services\PlanCatalog;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionCreditsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CreditLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($this->owner);
        $this->ledger = app(CreditLedger::class);
        Http::preventStrayRequests();
    }

    private function free(): void
    {
        $this->ledger->assign('free', $this->owner);
    }

    private function reserve(string $key): CreditEntry
    {
        return $this->ledger->reserve('search:'.$key, $this->owner->id, 'openai', 'gpt-4.1-mini');
    }

    public function test_four_approved_plans_prices_and_allowances(): void
    {
        foreach (['free' => [0, 5, 1, 1], 'starter' => [2900, 450, 1, 2], 'pro' => [9900, 1500, 5, 5], 'agency' => [24900, 4000, 10, 20]] as $key => $values) {
            $p = app(PlanCatalog::class)->definition($key);
            $this->assertSame($values, [$p['price_cents'], $p['monthly_searches'], $p['brands'], $p['members']]);
            $this->ledger->assign($key, $this->owner);
            $this->assertSame($values[1], $this->ledger->balance()['allowance']);
        }
        $this->assertSame(['openai'], app(PlanCatalog::class)->platforms());
        $this->assertFalse(app(PlanCatalog::class)->allows('scheduled_monitoring'));
    }

    public function test_monthly_limit_is_enforced_and_duplicate_reservations_do_not_charge_twice(): void
    {
        $this->free();
        $first = $this->reserve('one');
        $this->assertSame($first->id, $this->reserve('one')->id);
        $first->update(['status' => 'used']);
        foreach (['two', 'three', 'four', 'five'] as $key) {
            $this->reserve($key)->update(['status' => 'used']);
        }
        $this->assertSame(5, $this->ledger->balance()['used']);
        $this->assertSame(0, $this->ledger->balance()['remaining']);
        $this->assertSame(5, CreditEntry::count());
        $this->expectException(ValidationException::class);
        $this->reserve('six');
    }

    public function test_reservations_are_shared_across_brands_and_operations(): void
    {
        $this->ledger->assign('pro', $this->owner);
        $this->reserve('first');
        $context = app(TenantContext::class);
        $brand = MonitoredBrand::factory()->create();
        $context->set($context->organizationId, $brand->id, $this->owner);
        $this->reserve('second');
        $b = $this->ledger->balance();
        $this->assertSame(2, $b['reserved']);
        $this->assertSame(1498, $b['remaining']);
    }

    public function test_period_reset_has_no_rollover_and_old_snapshots_remain(): void
    {
        $this->free();
        $this->reserve('used')->update(['status' => 'used']);
        $old = $this->ledger->period();
        $this->travelTo($old->ends_at->addSecond());
        $b = $this->ledger->balance();
        $this->assertSame(5, $b['allowance']);
        $this->assertSame(0, $b['used']);
        $this->assertSame(5, $b['remaining']);
        $this->assertSame(2, CreditPeriod::count());
        $this->assertSame(1, CreditEntry::where('credit_period_id', $old->id)->where('status', 'used')->count());
    }

    public function test_catalog_edits_do_not_rewrite_active_or_historical_terms(): void
    {
        $p = $this->ledger->period();
        config(['plans.definitions.agency.monthly_searches' => 9999, 'plans.definitions.agency.price_cents' => 888]);
        $this->assertSame(4000, $this->ledger->balance()['allowance']);
        $this->assertSame(24900, app(PlanCatalog::class)->current()['price_cents']);
        $this->ledger->assign('agency', $this->owner);
        $this->assertSame(9999, $this->ledger->balance()['allowance']);
        $this->assertSame(24900, $p->fresh()->plan_snapshot['price_cents']);
        $this->assertSame(888, PlanChange::latest('id')->first()->after_snapshot['price_cents']);
    }

    public function test_downgrade_cannot_discard_committed_credits(): void
    {
        foreach (range(1, 6) as $n) {
            $this->reserve((string) $n);
        }
        try {
            $this->free();
            $this->fail('Downgrade must reject commitments.');
        } catch (ValidationException) {
            $this->assertSame('agency', app(TenantContext::class)->organization()->plan_key);
            $this->assertSame(6, $this->ledger->balance()['reserved']);
        }
    }

    public function test_plan_assignment_requires_platform_authority(): void
    {
        $this->owner->forceFill(['is_platform_admin' => false])->save();
        $this->actingAs($this->owner)->put('/organization/plan', ['plan_key' => 'pro'])->assertForbidden();
        $this->assertSame('agency', app(TenantContext::class)->organization()->plan_key);
    }

    public function test_free_requires_verified_email_and_public_registration_stays_disabled(): void
    {
        $this->free();
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->owner)->get('/verify-email')->assertOk();
        $this->get('/register')->assertNotFound();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Verify your email');
        $this->reserve('unverified');
    }

    public function test_free_account_allowance_cannot_be_multiplied_by_multiple_organizations(): void
    {
        $this->free();
        foreach (range(1, 5) as $n) {
            $this->reserve((string) $n)->update(['status' => 'used']);
        }
        $org = Organization::factory()->create(['owner_id' => $this->owner->id]);
        $attributes = MonitoredBrand::factory()->make(['organization_id' => $org->id])->getAttributes();
        $id = DB::table('monitored_brands')->insertGetId($attributes);
        app(TenantContext::class)->set($org->id, $id, $this->owner);
        $this->assertSame(5, $this->ledger->balance()['remaining']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('per verified account');
        $this->reserve('extra');
    }

    public function test_failed_admission_rolls_back_all_concurrent_batch_reservations(): void
    {
        $this->free();
        config(['plans.credit_weights.openai' => ['*' => 1, 'gpt-4.1-mini' => 3]]);
        try {
            DB::transaction(function () {
                $this->reserve('first');
                $this->reserve('second');
            });
            $this->fail('Atomic reservation must reject overdraw.');
        } catch (ValidationException) {
            $this->assertSame(0, CreditEntry::count());
            $this->assertSame(5, $this->ledger->balance()['remaining']);
        }
    }

    public function test_cancelled_pending_campaign_releases_reserved_credits(): void
    {
        $batch = app(PilotStudy::class)->prepare($this->owner);
        $this->ledger->reserveBatch($batch);
        $this->assertSame(12, $this->ledger->balance()['reserved']);
        app(BatchManager::class)->cancel($batch);
        $this->assertSame(0, $this->ledger->balance()['reserved']);
        $this->assertSame(12, CreditEntry::where('status', 'released')->count());
    }

    public function test_retry_reuses_released_operation_without_duplicate_credit_charge(): void
    {
        $first = $this->reserve('retry');
        $first->update(['status' => 'released']);
        $again = $this->reserve('retry');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CreditEntry::count());
        $again->update(['status' => 'used']);
        $this->assertSame(1, $this->ledger->balance()['used']);
        $this->expectException(ValidationException::class);
        $this->reserve('retry');
    }

    public function test_model_weights_are_configurable_and_snapshot_on_reservation(): void
    {
        config(['plans.credit_weights.openai' => ['*' => 1, 'gpt-4.1-mini' => 2]]);
        $entry = $this->reserve('weighted');
        $this->assertSame(2, $entry->credits);
        config(['plans.credit_weights.openai' => ['*' => 1, 'gpt-4.1-mini' => 3]]);
        $this->assertSame(2, $this->reserve('weighted')->credits);
        $this->assertSame(3998, $this->ledger->balance()['remaining']);
    }

    public function test_credits_never_override_zero_financial_limits(): void
    {
        $this->assertSame(4000, $this->ledger->balance()['remaining']);
        app(CostControl::class)->settings()->update(['monthly_limit_micros' => 0]);
        $batch = app(PilotStudy::class)->prepare($this->owner);
        $preview = app(PilotStudy::class)->preview($batch);
        $this->assertFalse($preview['ready']);
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_customer_plan_pages_show_credits_and_no_internal_costs(): void
    {
        $this->owner->forceFill(['is_platform_admin' => false])->save();
        $this->actingAs($this->owner);
        foreach (['/organization?section=usage', '/batches', '/tests', '/pilot'] as $url) {
            $this->get($url)->assertOk()->assertSee('Monthly search credits')->assertDontSee('Safety reservation')->assertDontSee('Search-tool fees in estimate')->assertDontSee('Approved pilot ceiling (USD)');
        }
        $this->get('/organization?section=usage')->assertSee('$29.00')->assertSee('$99.00')->assertSee('$249.00')->assertSee('Next reset');
    }

    public function test_free_and_starter_advanced_features_are_enforced_server_side(): void
    {
        foreach (['free', 'starter'] as $key) {
            $this->ledger->assign($key, $this->owner);
            $this->actingAs($this->owner)->get('/improvements')->assertForbidden();
            $this->get('/opportunities')->assertForbidden();
            $this->get('/dashboard')->assertOk()->assertDontSee('Review recommendations');
        }
    }

    public function test_free_account_rate_limit_applies_across_entry_points(): void
    {
        $this->free();
        $quota = app(OrganizationQuota::class);
        foreach (range(1, 3) as $n) {
            $quota->assertRequest('openai', 1, $this->owner->id);
        }$this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Account request rate limit');
        $quota->assertRequest('openai', 1, $this->owner->id);
    }

    public function test_real_request_pipeline_reconciles_success_rejection_and_uncertain_outcome_with_mocks(): void
    {
        config(['intelligence.openai_key' => 'mock-credit-key']);
        $provider = AiProvider::where('identifier', 'openai')->firstOrFail();
        $prompt = SearchPrompt::firstOrFail();
        $market = MarketScenario::firstOrFail();
        $calls = 0;
        Http::fake(['api.openai.com/*' => function () use (&$calls) {
            $calls++;
            if ($calls === 2) {
                return Http::response(['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota']], 429);
            }if ($calls === 3) {
                throw new ConnectionException('Simulated uncertain outcome');
            }

            return Http::response(['id' => 'resp_credit_saved', 'model' => 'gpt-4.1-mini', 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Nandini Jungle Resort is recommended.', 'annotations' => []]]]], 'usage' => ['input_tokens' => 8528, 'output_tokens' => 512, 'total_tokens' => 9040]]);
        }]);
        $execute = fn () => app(TestExecutor::class)->execute($this->owner, $provider, $prompt, $market, 'gpt-4.1-mini', (string) Str::uuid());
        $success = $execute();
        $this->assertSame('used', CreditEntry::where('test_run_id', $success->id)->value('status'));
        $failure = $execute();
        $this->assertSame('released', CreditEntry::where('test_run_id', $failure->id)->value('status'));
        $unknown = $execute();
        $this->assertSame('reserved', CreditEntry::where('test_run_id', $unknown->id)->value('status'));
        $this->assertSame(1, $this->ledger->balance()['used']);
        $this->assertSame(1, $this->ledger->balance()['reserved']);
    }

    public function test_downgrade_keeps_inactive_brand_history_and_blocks_reactivation_above_limit(): void
    {
        $extra = MonitoredBrand::factory()->create();
        $extra->forceFill(['active' => false])->save();
        $this->ledger->assign('starter', $this->owner);
        $this->assertSame(1, app(PlanCatalog::class)->current()['brands']);
        $this->assertSame(2, MonitoredBrand::count());
        $this->expectException(ValidationException::class);
        app(OrganizationQuota::class)->assertCapacity('brands');
    }

    public function test_verification_links_are_signed_and_can_only_verify_the_authenticated_account(): void
    {
        $this->free();
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->owner);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $this->owner->id, 'hash' => sha1($this->owner->email)]);
        $this->get('/verify-email/'.$this->owner->id.'/invalid')->assertForbidden();
        $this->get($url)->assertRedirect('/dashboard');
        $this->assertTrue($this->owner->fresh()->hasVerifiedEmail());
        $this->assertSame(1, $this->reserve('verified')->credits);
    }

    public function test_unimplemented_platforms_are_not_selectable_even_if_a_catalog_lists_them(): void
    {
        AiProvider::create(['identifier' => 'future', 'name' => 'Future provider', 'enabled' => true, 'model_configuration' => []]);
        config(['plans.implemented_providers' => ['openai', 'future'], 'plans.definitions.agency.providers' => ['openai', 'future']]);
        $this->assertSame(['openai'], app(PlanCatalog::class)->platforms());
    }

    public function test_zero_organization_budget_blocks_campaign_credit_reservations(): void
    {
        $manager = app(BatchManager::class);
        $batch = $manager->preview($this->owner, ['creation_id' => (string) Str::uuid(), 'name' => 'No organization budget', 'prompt_ids' => [SearchPrompt::firstOrFail()->id], 'market_ids' => [MarketScenario::where('country', 'AU')->firstOrFail()->id], 'repetitions' => 1]);
        app(TenantContext::class)->organization()->forceFill(['monthly_limit_micros' => 0])->save();
        try {
            $manager->start($batch);
            $this->fail('Organization budget must block approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quota', $exception->errors());
        }
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('draft', $batch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancellation_after_claim_but_before_dispatch_releases_the_last_credit(): void
    {
        $batch = app(PilotStudy::class)->prepare($this->owner);
        $this->ledger->reserveBatch($batch);
        $batch->update(['status' => 'running']);
        $executor = \Mockery::mock(TestExecutor::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function () use ($batch) {
            app(BatchManager::class)->cancel($batch);
            throw ValidationException::withMessages(['batch' => 'Cancelled before dispatch']);
        });
        $this->app->instance(TestExecutor::class, $executor);
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame(0, $this->ledger->balance()['reserved']);
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_recovering_an_undispatched_cancelled_claim_releases_its_credit(): void
    {
        $batch = app(PilotStudy::class)->prepare($this->owner);
        $this->ledger->reserveBatch($batch);
        $item = $batch->items()->firstOrFail();
        $item->update(['status' => 'processing', 'submission_id' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(5), 'attempts' => 1]);
        app(BatchManager::class)->cancel($batch);
        $this->assertSame(1, $this->ledger->balance()['reserved']);
        app(BatchProcessor::class)->recover();
        $this->assertSame(0, $this->ledger->balance()['reserved']);
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_cancellation_retains_a_persisted_request_reservation_even_with_a_stale_cancelled_item(): void
    {
        $batch = app(PilotStudy::class)->prepare($this->owner);
        $this->ledger->reserveBatch($batch);
        $item = $batch->items()->firstOrFail();
        $prompt = SearchPrompt::findOrFail($item->search_prompt_id);
        $run = TestRun::create(['user_id' => $this->owner->id, 'ai_provider_id' => $batch->ai_provider_id, 'search_prompt_id' => $prompt->id, 'market_scenario_id' => $item->market_scenario_id, 'test_batch_id' => $batch->id, 'batch_item_id' => $item->id, 'submission_id' => (string) Str::uuid(), 'model' => $batch->model, 'prompt_text' => $prompt->text, 'prompt_version' => $prompt->version, 'market_snapshot' => $item->market_snapshot, 'request_settings' => [], 'status' => 'running', 'repetition' => 1, 'executed_at' => now()]);
        CreditEntry::where('batch_item_id', $item->id)->update(['test_run_id' => $run->id]);
        $item->update(['status' => 'cancelled']);
        $this->ledger->cancelBatch($batch);
        $this->assertSame(1, $this->ledger->balance()['reserved']);
        $this->assertSame('reserved', CreditEntry::where('test_run_id', $run->id)->value('status'));
        Http::assertNothingSent();
    }
}
