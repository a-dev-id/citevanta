<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BatchItem;
use App\Models\CostEntry;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\CostControl;
use App\Services\RequestPricing;
use App\Services\TestExecutor;
use App\Services\VisibilityAnalysis;
use App\Services\VisibilityMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BatchTestingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['intelligence.openai_key' => 'mock-key', 'intelligence.daily_limit' => 100]);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->is_admin = true;
        $u->save();
        $this->joinLegacyWorkspace($u);

        return $u;
    }

    private function batch(int $markets = 1, int $repeat = 1): TestBatch
    {
        return app(BatchManager::class)->preview($this->admin(), ['creation_id' => (string) Str::uuid(), 'name' => 'Controlled test batch', 'prompt_ids' => [SearchPrompt::first()->id], 'market_ids' => MarketScenario::whereIn('country', ['AU', 'US', 'GB', 'ID'])->limit($markets)->pluck('id')->all(), 'repetitions' => $repeat]);
    }

    private function payload(): array
    {
        return ['id' => 'resp_mock', 'status' => 'completed', 'model' => 'gpt-4.1-mini-2025-04-14', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '1. Nandini Jungle by Hanging Gardens is recommended.', 'annotations' => [['type' => 'url_citation', 'url' => 'https://nandinibali.com/', 'title' => 'Nandini', 'start_index' => 3, 'end_index' => 38]]]]]], 'usage' => ['input_tokens' => 8528, 'output_tokens' => 512, 'total_tokens' => 9040]];
    }

    private function fakeSuccess(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->payload())]);
    }

    private function start(TestBatch $b): void
    {
        app(BatchManager::class)->start($b);
    }

    private function process(int $limit = 1): int
    {
        return app(BatchProcessor::class)->process($limit);
    }

    private function expectBlocked(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a spending or state validation failure.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function claim(BatchItem $item): BatchItem
    {
        $item->update(['status' => 'processing', 'attempts' => 1, 'submission_id' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(5)]);

        return $item->fresh();
    }

    private function runClaim(BatchItem $item): TestRun
    {
        return app(TestExecutor::class)->execute($item->batch->user, $item->batch->provider, new SearchPrompt($item->prompt_snapshot), new MarketScenario($item->market_snapshot), $item->batch->model, $item->submission_id, $item);
    }

    public function test_batch_generation_snapshots_counts_and_duplicate_preview(): void
    {
        SpendingSetting::first()->update(['batch_request_limit' => 50]);
        $extra = SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Travel', 'text' => 'Luxury resorts with jungle villas', 'version' => 2, 'active' => true]);
        $data = ['creation_id' => (string) Str::uuid(), 'name' => 'Two prompts four markets', 'prompt_ids' => SearchPrompt::pluck('id')->all(), 'market_ids' => MarketScenario::whereIn('country', ['AU', 'US', 'GB', 'ID'])->pluck('id')->all(), 'repetitions' => 3];
        $b = app(BatchManager::class)->preview($this->admin(), $data);
        $this->assertSame(24, $b->request_count);
        $this->assertCount(24, $b->items);
        $this->assertGreaterThan(0, $b->estimated_micros);
        $this->assertGreaterThan($b->estimated_micros, $b->reserved_estimate_micros);
        $same = app(BatchManager::class)->preview($this->admin(), $data);
        $this->assertSame($b->id, $same->id);
        $this->assertDatabaseCount('test_batches', 1);
        $this->assertDatabaseCount('test_runs', 0);
        Http::assertNothingSent();
        $extra->update(['text' => 'Changed after preview', 'version' => 3]);
        $this->assertSame(2, $b->items->firstWhere('search_prompt_id', $extra->id)->prompt_snapshot['version']);
    }

    public function test_success_uses_shared_executor_phase_two_and_bounded_execution(): void
    {
        $this->fakeSuccess();
        $b = $this->batch(2, 2);
        $this->start($b);
        $this->assertSame(1, $this->process());
        $this->assertSame(1, $b->items()->where('status', 'completed')->count());
        $this->assertSame(3, $b->items()->where('status', 'pending')->count());
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(1, $this->process());
        }
        $this->assertSame(0, $this->process());
        $this->assertSame('completed', $b->fresh()->status);
        Http::assertSentCount(4);
        $this->assertDatabaseCount('test_runs', 4);
        $this->assertDatabaseCount('cost_entries', 4);
        $run = $b->runs()->first();
        $this->assertNotNull($run->response->analyzed_at);
        $this->assertTrue($run->response->assessments->first()->recommended);
        $this->assertNull($run->response->assessments->first()->rank);
        $this->assertSame('nandini_official', $run->response->citations->first()->classification);
        $this->assertEquals('0.014232', $run->usage->estimated_cost);
        $this->assertSame(17790, $run->cost->committed_micros);
        $metrics = app(VisibilityMetrics::class);
        $this->assertSame(4, $metrics->summary($metrics->runs())['total']);
        $this->actingAs($this->admin())->get('/batches/'.$b->id)->assertOk()->assertSee('View response');
        $this->get('/tests/'.$run->id)->assertOk();
    }

    public function test_request_snapshots_survive_prompt_and_market_edits(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $item = $b->items->first();
        SearchPrompt::first()->update(['text' => 'Changed question', 'version' => 99, 'active' => false]);
        MarketScenario::find($item->market_scenario_id)->update(['country' => 'XX', 'search_settings' => ['unsupported' => 'value']]);
        $this->start($b);
        $this->process();
        Http::assertSent(fn ($r) => $r['input'] === 'Best luxury jungle resorts in Ubud Bali' && $r['tools'][0]['type'] === 'web_search' && $r['tools'][0]['user_location'] === ['type' => 'approximate', 'country' => 'AU'] && $r['max_tool_calls'] === 1);
        $this->assertSame(1, $b->runs()->first()->prompt_version);
    }

    public function test_zero_monthly_limit_blocks_batch_and_single_without_creating_requests(): void
    {
        SpendingSetting::first()->update(['monthly_limit_micros' => 0]);
        $b = $this->batch();
        $this->expectBlocked(fn () => $this->start($b), 'Monthly');
        $this->expectBlocked(fn () => app(TestExecutor::class)->execute($this->admin(), AiProvider::first(), SearchPrompt::first(), MarketScenario::first(), config('intelligence.model'), (string) Str::uuid()), 'Monthly');
        Http::assertNothingSent();
        $this->assertDatabaseCount('test_runs', 0);
    }

    public function test_safety_reservation_is_committed_before_dispatch_and_limit_stops_further_work(): void
    {
        $b = $this->batch(2);
        $this->start($b);
        Http::fake(function () {
            $this->assertSame(81500, app(CostControl::class)->balance()['committed']);

            return Http::response($this->payload());
        });
        $this->process();
        SpendingSetting::first()->update(['monthly_limit_micros' => 18000]);
        $this->assertSame(0, $this->process());
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame(1, $b->runs()->count());
        Http::assertSentCount(1);
    }

    public function test_cancellation_stops_pending_work_but_preserves_in_flight_result_and_cost(): void
    {
        $b = $this->batch(3);
        $this->start($b);
        Http::fake(function () use ($b) {
            app(BatchManager::class)->cancel($b);

            return Http::response($this->payload());
        });
        $this->process();
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertSame(1, $b->items()->where('status', 'completed')->count());
        $this->assertSame(2, $b->items()->where('status', 'cancelled')->count());
        $this->assertSame(17790, $b->runs()->first()->cost->committed_micros);
        $this->assertSame(0, $this->process());
        Http::assertSentCount(1);
    }

    public function test_confirmed_rate_limit_retries_honor_backoff_and_only_success_counts_in_visibility(): void
    {
        Http::fakeSequence()->push(['error' => ['code' => 'rate_limit_exceeded', 'type' => 'rate_limit_error']], 429, ['Retry-After' => '120'])->push($this->payload());
        $b = $this->batch();
        $this->start($b);
        $this->process();
        $item = $b->items()->first();
        $this->assertSame('retry_wait', $item->status);
        $this->assertTrue($item->available_at->gte(now()->addSeconds(119)));
        $this->assertSame(0, $this->process());
        $this->assertSame(0, app(CostControl::class)->balance()['committed']);
        $this->travel(121)->seconds();
        $this->process();
        Http::assertSentCount(2);
        $this->assertSame(2, $b->runs()->count());
        $this->assertSame('completed', $b->fresh()->status);
        $metrics = app(VisibilityMetrics::class);
        $this->assertSame(1, $metrics->summary($metrics->runs())['total']);
    }

    public function test_retries_are_bounded_and_count_toward_batch_request_cap(): void
    {
        SpendingSetting::first()->update(['batch_request_limit' => 2]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'type' => 'rate_limit_error']], 429)]);
        $b = $this->batch();
        $this->start($b);
        $this->process();
        $this->travel(61)->seconds();
        $this->process();
        $this->travel(121)->seconds();
        $this->process();
        Http::assertSentCount(2);
        $this->assertSame(2, $b->runs()->count());
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertStringContainsString('Per-batch', $b->fresh()->status_note);
    }

    public function test_unknown_outcome_is_never_retried_and_keeps_its_reservation(): void
    {
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $b = $this->batch(2);
        $this->start($b);
        $this->process();
        $this->process();
        Http::assertSentCount(1);
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame('interrupted', $b->items()->first()->status);
        $this->assertSame('uncertain', $b->runs()->first()->cost->status);
        $this->assertSame(81500, app(CostControl::class)->balance()['committed']);
    }

    public function test_claim_without_a_persisted_request_can_be_recovered_safely(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $this->start($b);
        $item = $this->claim($b->items()->first());
        $b->update(['status' => 'paused']);
        app(BatchProcessor::class)->recover();
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame(0, $item->fresh()->attempts);
        Http::assertNothingSent();
        $this->start($b);
        $this->process();
        Http::assertSentCount(1);
    }

    public function test_saved_success_after_worker_interruption_is_reconciled_without_duplicate_call(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $this->start($b);
        $item = $this->claim($b->items()->first());
        $run = $this->runClaim($item);
        $this->assertSame('completed', $run->status);
        app(BatchProcessor::class)->recover();
        $this->process();
        Http::assertSentCount(1);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame($run->id, $item->fresh()->test_run_id);
        $this->assertNotNull($run->response->fresh()->analyzed_at);
    }

    public function test_reserved_but_unconfirmed_request_is_interrupted_and_skipped_on_resume(): void
    {
        $this->fakeSuccess();
        $b = $this->batch(2);
        $this->start($b);
        $item = $this->claim($b->items()->first());
        $snapshot = $item->prompt_snapshot;
        $market = $item->market_snapshot;
        $run = app(CostControl::class)->reserve(['user_id' => $b->user_id, 'ai_provider_id' => $b->ai_provider_id, 'search_prompt_id' => $item->search_prompt_id, 'market_scenario_id' => $item->market_scenario_id, 'submission_id' => $item->submission_id, 'model' => $b->model, 'prompt_version' => $snapshot['version'], 'prompt_text' => $snapshot['text'], 'market_snapshot' => $market, 'request_settings' => [], 'status' => 'running', 'executed_at' => now()->subMinutes(5), 'test_batch_id' => $b->id, 'batch_item_id' => $item->id], $item);
        app(BatchProcessor::class)->recover();
        $this->assertSame('interrupted', $item->fresh()->status);
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame(81500, $run->cost->committed_micros);
        Http::assertNothingSent();
        $this->start($b);
        $this->process();
        Http::assertSentCount(1);
        $this->assertSame(2, $b->runs()->count());
    }

    public function test_pricing_missing_expired_or_changed_fails_closed(): void
    {
        $original = config('spending.pricing');
        config(['spending.pricing.models' => []]);
        $b = $this->batch();
        $this->assertNull($b->estimated_micros);
        $this->expectBlocked(fn () => $this->start($b), 'pricing');
        config(['spending.pricing' => $original]);
        $priced = $this->batch();
        config(['spending.pricing.valid_until' => now()->subDay()->format('Y-m-d')]);
        $this->expectBlocked(fn () => $this->start($priced), 'pricing');
        config(['spending.pricing' => $original]);
        $this->start($priced);
        config(['spending.pricing.version' => 'changed-price-version']);
        $this->process();
        $this->assertSame('paused', $priced->fresh()->status);
        $this->assertDatabaseCount('test_runs', 0);
        Http::assertNothingSent();
    }

    public function test_pre_control_usage_requires_a_confirmed_opening_allowance(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $item = $b->items->first();
        $legacy = TestRun::create(['user_id' => $b->user_id, 'ai_provider_id' => $b->ai_provider_id, 'search_prompt_id' => $item->search_prompt_id, 'market_scenario_id' => $item->market_scenario_id, 'submission_id' => (string) Str::uuid(), 'model' => $b->model, 'prompt_version' => 1, 'prompt_text' => $item->prompt_snapshot['text'], 'market_snapshot' => $item->market_snapshot, 'request_settings' => [], 'status' => 'completed', 'executed_at' => now()]);
        $legacy->response()->create(['status' => 'completed', 'text' => 'Existing saved answer.', 'raw_metadata' => $this->payload()]);
        $this->assertNull(app(CostControl::class)->balance()['opening']);
        $this->expectBlocked(fn () => $this->start($b), 'pre-control');
        $this->expectBlocked(fn () => app(CostControl::class)->confirmOpening(0, $b->user_id, 'Reviewed prior application usage.'), 'at least');
        app(CostControl::class)->confirmOpening(20000, $b->user_id, 'Reviewed provider usage including uncertain charges.');
        $this->start($b);
        $this->process();
        Http::assertSentCount(1);
        $this->assertSame(20000, app(CostControl::class)->balance()['opening']);
    }

    public function test_batch_pages_and_settings_are_protected_and_start_requires_explicit_confirmation(): void
    {
        foreach (['/batches', '/batches/create'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create())->get('/batches')->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get('/batches')->assertOk();
        $this->get('/batches/create')->assertOk()->assertSee('selected market and language');
        $this->get('/batch-settings')->assertNotFound();
        $b = $this->batch(4, 3);
        $this->post('/batches/'.$b->id.'/start', ['confirm_credits' => 1])->assertSessionHasErrors('large_warning');
        $this->post('/batches/'.$b->id.'/start', ['confirm_credits' => 1, 'large_warning' => 1])->assertRedirect();
        $this->assertSame('running', $b->fresh()->status);
        Http::assertNothingSent();
        $this->post('/batches/'.$b->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $b->fresh()->status);
    }

    public function test_over_limit_batches_and_duplicate_prompt_inputs_are_rejected(): void
    {
        $this->expectBlocked(fn () => $this->batch(4, 10), 'per-batch');
        $this->actingAs($this->admin())->get('/batches/create');
        $token = session('batch_creations')[0];
        $this->post('/batches/preview', ['creation_id' => $token, 'name' => 'Duplicate input', 'prompt_ids' => [SearchPrompt::first()->id, SearchPrompt::first()->id], 'market_ids' => [MarketScenario::where('country', 'AU')->value('id')], 'repetitions' => 1])->assertSessionHasErrors('prompt_ids.0');
        Http::assertNothingSent();
    }

    public function test_overlapping_processor_and_single_execution_locks_prevent_dispatch(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $this->start($b);
        $lock = Cache::lock('intelligence:batch-processor', 60);
        $lock->get();
        $this->assertSame(0, $this->process());
        $lock->release();
        $single = Cache::lock('intelligence:execution', 120);
        $single->get();
        $this->process();
        $single->release();
        Http::assertNothingSent();
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame(0, $b->items()->first()->attempts);
    }

    public function test_non_rate_limit_errors_are_not_automatically_retried(): void
    {
        foreach ([[400, 'unsupported_parameter'], [401, 'invalid_api_key'], [403, 'permission_denied'], [404, 'model_not_found'], [429, 'insufficient_quota'], [503, 'server_error']] as [$status,$code]) {
            Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => $code, 'type' => 'invalid_request_error']], $status)]);
            $b = $this->batch();
            $this->start($b);
            $this->process();
            $this->process();
            $this->assertSame(1, $b->runs()->count());
            $this->assertSame(1, $b->items()->first()->attempts);
            $this->assertContains($b->items()->first()->status, ['failed', 'interrupted']);
        }
    }

    public function test_monthly_accounting_rolls_over_without_losing_prior_reservations(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 23:59:00', 'UTC'));
        $this->fakeSuccess();
        $b = $this->batch(2);
        $this->start($b);
        $this->process();
        $this->assertSame('2026-11', app(CostControl::class)->balance()['month']->month);
        $this->assertSame(17790, app(CostControl::class)->balance()['committed']);
        $this->travel(2)->minutes();
        $this->assertSame(0, app(CostControl::class)->balance()['committed']);
        $this->process();
        $this->assertSame('2026-12', app(CostControl::class)->balance()['month']->month);
        $this->assertSame(17790, app(CostControl::class)->balance()['committed']);
        $this->assertSame(2, CostEntry::count());
        Http::assertSentCount(2);
    }

    public function test_single_request_form_is_preserved_and_is_subject_to_the_shared_monthly_guard(): void
    {
        $this->fakeSuccess();
        $this->actingAs($this->admin())->get('/tests')->assertOk();
        $submission = session('test_submissions')[0];
        $data = ['provider_id' => AiProvider::first()->id, 'prompt_id' => SearchPrompt::first()->id, 'market_id' => MarketScenario::first()->id, 'model' => config('intelligence.model'), 'submission_id' => $submission];
        $this->post('/tests', $data)->assertRedirect('/tests/1');
        $this->post('/tests', $data)->assertRedirect('/tests/1');
        Http::assertSentCount(1);
        $run = TestRun::first();
        $this->assertNull($run->test_batch_id);
        $this->assertSame('settled', $run->cost->status);
        $this->assertSame(9040, $run->usage->total_tokens);
    }

    public function test_cost_settings_routes_are_removed_without_altering_backend_precision(): void
    {
        $this->actingAs($this->admin())->get('/batch-settings')->assertNotFound();
        $this->put('/batch-settings', ['monthly_limit' => '0.01'])->assertNotFound();
        $this->assertSame(10000, RequestPricing::micros('0.01'));
        $this->get('/settings')->assertOk()->assertDontSee('Cost Controls')->assertDontSee('mock-key');
    }

    public function test_rate_limit_retry_exhaustion_stops_after_three_attempts(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'type' => 'rate_limit_error']], 429)]);
        $b = $this->batch();
        $this->start($b);
        $this->process();
        $this->travel(61)->seconds();
        $this->process();
        $this->travel(121)->seconds();
        $this->process();
        $this->travel(241)->seconds();
        $this->process();
        Http::assertSentCount(3);
        $this->assertSame('failed', $b->items()->first()->status);
        $this->assertSame('completed_with_errors', $b->fresh()->status);
    }

    public function test_reconciliation_preserves_existing_phase_two_manual_corrections(): void
    {
        $this->fakeSuccess();
        $b = $this->batch();
        $this->start($b);
        $item = $this->claim($b->items()->first());
        $run = $this->runClaim($item);
        app(VisibilityAnalysis::class)->analyze($run->response);
        $assessment = $run->response->assessments()->first();
        $assessment->update(['recommended' => false, 'rank' => null, 'manual' => true, 'reviewed_by' => $b->user_id, 'review_note' => 'Administrator reviewed the existing saved evidence.']);
        app(BatchProcessor::class)->recover();
        $this->assertFalse($assessment->fresh()->recommended);
        $this->assertTrue($assessment->fresh()->manual);
        $metrics = app(VisibilityMetrics::class);
        $this->assertSame(0, $metrics->summary($metrics->runs())['recommended']);
        Http::assertSentCount(1);
    }

    public function test_missing_response_usage_keeps_reserve_and_pauses_remaining_work(): void
    {
        $payload = $this->payload();
        unset($payload['usage']);
        Http::fake(['api.openai.com/*' => Http::response($payload)]);
        $b = $this->batch(2);
        $this->start($b);
        $this->process();
        $this->assertSame('completed', $b->items()->first()->status);
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame(81500, app(CostControl::class)->balance()['committed']);
        $this->assertNull($b->runs()->first()->cost->usage_cost_micros);
        $this->assertSame(0, $this->process());
        Http::assertSentCount(1);
    }

    public function test_cron_command_is_bounded_and_returns_without_a_persistent_worker(): void
    {
        $this->fakeSuccess();
        $b = $this->batch(4);
        $this->start($b);
        $this->artisan('intelligence:batches-process', ['--limit' => 4])->assertExitCode(1);
        Http::assertNothingSent();
        $this->artisan('intelligence:batches-process', ['--limit' => 1])->assertExitCode(0);
        Http::assertSentCount(1);
        $this->assertSame(3, $b->items()->where('status', 'pending')->count());
    }

    public function test_configuration_or_billing_failures_pause_other_pending_requests(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota']], 429)]);
        $b = $this->batch(3);
        $this->start($b);
        $this->process();
        $this->process();
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame(2, $b->items()->where('status', 'pending')->count());
        Http::assertSentCount(1);
    }

    public function test_provider_usage_exceeding_the_reservation_is_recorded_and_pauses_new_work(): void
    {
        $payload = $this->payload();
        $payload['usage'] = ['input_tokens' => 500000, 'output_tokens' => 100000, 'total_tokens' => 600000];
        Http::fake(['api.openai.com/*' => Http::response($payload)]);
        $b = $this->batch(2);
        $this->start($b);
        $this->process();
        $this->assertSame('paused', $b->fresh()->status);
        $this->assertSame('overrun', $b->runs()->first()->cost->status);
        $this->assertGreaterThan(81500, $b->runs()->first()->cost->committed_micros);
        $this->assertSame(0, $this->process());
        Http::assertSentCount(1);
    }

    public function test_versioned_pricing_uses_actual_tokens_cached_rates_and_tool_calls_without_double_counting(): void
    {
        $price = app(RequestPricing::class)->snapshot('gpt-4.1-mini');
        $payload = $this->payload();
        $payload['usage'] = ['input_tokens' => 8500, 'input_tokens_details' => ['cached_tokens' => 500], 'output_tokens' => 500, 'total_tokens' => 9000];
        $this->assertSame(14050, app(RequestPricing::class)->usage($payload, $price));
        $this->assertSame(17563, app(RequestPricing::class)->withSafety(14050, 25));
        $this->assertSame(config('spending.pricing.version'), $price['version']);
        config(['spending.pricing.valid_until' => 'not-a-date']);
        $this->expectBlocked(fn () => app(RequestPricing::class)->snapshot('gpt-4.1-mini'), 'pricing');
        Http::assertNothingSent();
    }

    public function test_rate_changes_without_a_version_bump_invalidate_the_saved_preview(): void
    {
        $batch = $this->batch();
        $models = config('spending.pricing.models');
        $models['gpt-4.1-mini']['search_micros'] = 20000;
        config(['spending.pricing.models' => $models]);
        $this->expectBlocked(fn () => $this->start($batch), 'fresh preview');
        Http::assertNothingSent();
    }
}
