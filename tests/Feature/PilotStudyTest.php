<?php

namespace Tests\Feature;

use App\Models\CostEntry;
use App\Models\Improvement;
use App\Models\PilotResultReview;
use App\Models\SearchPrompt;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\CostControl;
use App\Services\CreditLedger;
use App\Services\ImprovementIntelligence;
use App\Services\PilotQuality;
use App\Services\PilotStudy;
use App\Services\RequestPricing;
use App\Services\TenantContext;
use App\Services\VisibilityMetrics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PilotStudyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PilotStudy $study;

    private int $responses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($this->admin);
        config(['intelligence.openai_key' => 'mock-pilot-key', 'intelligence.daily_limit' => 100, 'pilot.first_live_review_required' => false]);
        Http::preventStrayRequests();
        $this->study = app(PilotStudy::class);
    }

    private function batch(): TestBatch
    {
        return $this->study->prepare($this->admin);
    }

    private function enabled(): TestBatch
    {
        $batch = $this->batch();
        $preview = $this->study->preview($batch);
        $this->study->enable($batch, $this->admin, 2000000, $preview['fingerprint']);

        return $batch->fresh();
    }

    private function payload(string $text = '1. Nandini Jungle Resort is recommended.', bool $citations = true): array
    {
        return ['id' => 'resp_pilot_'.(++$this->responses), 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => $citations ? [['type' => 'url_citation', 'url' => 'https://nandinibali.com/', 'title' => 'Nandini', 'start_index' => 3, 'end_index' => mb_strlen($text)]] : []]]]], 'usage' => ['input_tokens' => 8528, 'output_tokens' => 512, 'total_tokens' => 9040]];
    }

    private function fake(?string $text = null): void
    {
        Http::fake(['api.openai.com/*' => fn () => Http::response($this->payload($text ?? '1. Nandini Jungle Resort is recommended.'))]);
    }

    private function process(int $requests = 1): void
    {
        for ($i = 0; $i < $requests; $i++) {
            $this->assertSame(1, app(BatchProcessor::class)->process(1));
        }
    }

    private function approve(TestRun $run, string $decision = 'approved'): PilotResultReview
    {
        $quality = app(PilotQuality::class)->inspect($run);

        return app(PilotQuality::class)->review($run, $this->admin, $decision, $decision === 'flagged' ? 'Needs source review' : null, $quality['fingerprint'], true);
    }

    private function blocked(callable $callback, string $contains): void
    {
        try {
            $callback();
            $this->fail('Expected pilot control to block.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public function test_preparation_imports_the_exact_unbranded_plan_and_is_idempotent_without_api_calls(): void
    {
        $legacy = SearchPrompt::first()->only(['id', 'text', 'category', 'version', 'active']);
        $batch = $this->batch();
        $again = $this->batch();
        $this->assertSame($batch->id, $again->id);
        $this->assertSame(13, SearchPrompt::count());
        $this->assertSame(12, $batch->items()->count());
        $this->assertSame(12, $batch->request_limit);
        $this->assertSame(1, $batch->configuration['max_attempts']);
        $this->assertNull($batch->pilot_enabled_at);
        $this->assertSame($legacy, SearchPrompt::findOrFail($legacy['id'])->only(['id', 'text', 'category', 'version', 'active']));
        $this->assertSame('Best luxury resorts in Ubud Bali', config('pilot.prompts')[0]['text']);
        $this->assertSame('Luxury hotels in Ubud with romantic dining experiences', config('pilot.prompts')[11]['text']);
        $this->assertSame(4, collect(config('pilot.prompts'))->pluck('category')->unique()->count());
        $this->assertSame(1, $batch->items->pluck('market_scenario_id')->unique()->count());
        foreach ($batch->items as $item) {
            $this->assertDoesNotMatchRegularExpression('/nandini|hanging gardens/i', $item->prompt_snapshot['text']);
            $this->assertSame(1, (int) $item->repetition);
            $this->assertSame(['type' => 'approximate', 'country' => 'AU'], $item->market_snapshot['search_settings']);
        }
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_preview_includes_tool_fees_and_conservative_full_batch_budget_requirements(): void
    {
        $batch = $this->batch();
        $preview = $this->study->preview($batch);
        $this->assertTrue($preview['ready']);
        $this->assertSame(120000, $preview['search_fees']);
        $this->assertSame(978000, $preview['reserve']);
        $this->assertSame(978000, $preview['minimum_budget']);
        $expected = collect(config('pilot.prompts'))->sum(fn ($p) => app(RequestPricing::class)->quote($p['text'], $batch->model, 25)['estimate']);
        $this->assertSame($expected, $preview['estimated']);
        $this->assertLessThan($preview['reserve'], $preview['estimated']);
        Http::assertNothingSent();
    }

    public function test_zero_global_limit_keeps_preparation_available_but_prevents_all_enable_paths(): void
    {
        SpendingSetting::first()->update(['monthly_limit_micros' => 0]);
        $batch = $this->batch();
        $preview = $this->study->preview($batch);
        $this->assertFalse($preview['ready']);
        $this->blocked(fn () => $this->study->enable($batch, $this->admin, 2000000, $preview['fingerprint']), 'Approval requires');
        $this->blocked(fn () => app(BatchManager::class)->start($batch), 'Pilot execution is disabled');
        $this->actingAs($this->admin)->get('/pilot')->assertOk()->assertDontSee('Approve budget and enable pilot')->assertDontSee('pilot-budget');
        $this->assertNull($batch->fresh()->pilot_enabled_at);
        $this->assertSame(0, TestRun::count());
        $this->assertSame(0, CostEntry::count());
        Http::assertNothingSent();
    }

    public function test_forcing_running_state_does_not_bypass_pilot_approval_at_dispatch(): void
    {
        $batch = $this->batch();
        $batch->update(['status' => 'running']);
        $this->fake();
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_twelve_mocked_requests_reuse_existing_cron_pipeline_and_save_integrity_and_usage(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process(12);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(12, $batch->runs()->count());
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        Http::assertSentCount(12);
        foreach ($batch->runs as $run) {
            $quality = app(PilotQuality::class)->inspect($run);
            $this->assertTrue($quality['can_approve'], json_encode($quality));
            $this->assertSame('resp_pilot_'.$run->id, $run->response->api_response_id);
            $this->assertSame(1, $run->response->citations()->count());
            $this->assertSame(9040, $run->usage->total_tokens);
            $this->assertSame(14232, $run->cost->usage_cost_micros);
            $this->assertSame('settled', $run->cost->status);
            $this->assertTrue($run->response->assessments->first()->recommended);
        }
        $report = $this->study->report($batch);
        $this->assertSame(12, $report['provisional']['total']);
        $this->assertSame(0, $report['summary']['total']);
        $this->assertNull($report['summary']['mentionRate']);
        $this->assertSame(170784, $report['known_cost']);
        $this->assertSame(108480, $report['tokens']);
        $this->assertSame(0, app(VisibilityMetrics::class)->runs()->count());
    }

    public function test_quality_approval_controls_denominators_and_flagging_keeps_provisional_results_visible(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process(2);
        $runs = $batch->runs()->orderBy('id')->get();
        $this->approve($runs[0]);
        $this->approve($runs[1], 'flagged');
        $report = $this->study->report($batch);
        $this->assertSame(1, $report['summary']['total']);
        $this->assertSame(100.0, $report['summary']['mentionRate']);
        $this->assertSame(2, $report['provisional']['total']);
        $this->assertSame(1, $report['flagged']);
        $this->assertSame(1, app(VisibilityMetrics::class)->runs()->count());
        $this->approve($runs[0], 'flagged');
        $this->assertSame(0, $this->study->report($batch)['summary']['total']);
        $this->assertSame(3, PilotResultReview::count());
    }

    public function test_manual_corrections_invalidate_quality_approval_without_changing_original_answers(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $original = $run->response->only(['text', 'raw_metadata', 'api_response_id']);
        $this->approve($run);
        $assessment = $run->response->assessments()->first();
        $assessment->update(['manual' => true, 'recommended' => false, 'rank' => null, 'review_note' => 'Context mention only, verified by administrator']);
        $report = $this->study->report($batch);
        $this->assertSame(0, $report['summary']['total']);
        $this->assertSame(1, $report['unreviewed']);
        $this->assertSame(0, app(VisibilityMetrics::class)->runs()->count());
        $this->assertSame($original, $run->response->fresh()->only(['text', 'raw_metadata', 'api_response_id']));
        $this->approve($run);
        $this->assertSame(0.0, $this->study->report($batch)['summary']['recommendationRate']);
    }

    public function test_failed_pilot_request_is_saved_and_pauses_pending_work_without_retries(): void
    {
        $batch = $this->enabled();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'type' => 'rate_limit_error']], 429)]);
        $this->process();
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame(1, $batch->items()->where('status', 'failed')->count());
        $this->assertSame(11, $batch->items()->where('status', 'pending')->count());
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $report = $this->study->report($batch);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['summary']['total']);
        $this->assertSame(0, $report['provisional']['total']);
        $this->assertSame('released', $batch->runs->first()->cost->status);
        Http::assertSentCount(1);
    }

    public function test_unknown_provider_outcome_retains_reservation_and_is_not_automatically_repeated(): void
    {
        $batch = $this->enabled();
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $this->process();
        $run = $batch->runs()->first();
        $this->assertSame('uncertain', $run->cost->status);
        $this->assertSame(81500, $run->cost->committed_micros);
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame(1, $this->study->report($batch)['unknown_cost_count']);
    }

    public function test_organization_allowance_and_monthly_quota_are_checked_for_all_twelve_pending_items(): void
    {
        $batch = $this->batch();
        app(TenantContext::class)->organization()->forceFill(['monthly_limit_micros' => 10000])->save();
        $preview = $this->study->preview($batch);
        $this->assertFalse($preview['ready']);
        $this->blocked(fn () => $this->study->enable($batch, $this->admin, 2000000, $preview['fingerprint']), 'Approval requires');
        app(TenantContext::class)->organization()->forceFill(['monthly_limit_micros' => 100000000])->save();
        config(['plans.definitions.agency.monthly_searches' => 11]);
        app(CreditLedger::class)->assign('agency', $this->admin);
        $this->assertFalse($this->study->preview($batch)['checks']['Organization quotas']['passed']);
        Http::assertNothingSent();
    }

    public function test_small_batch_plan_or_missing_pricing_fails_safely(): void
    {
        app(TenantContext::class)->organization()->forceFill(['plan_key' => 'free'])->save();
        $this->blocked(fn () => $this->batch(), 'configured limits');
        $this->assertSame(0, TestBatch::count());
        app(TenantContext::class)->organization()->forceFill(['plan_key' => 'agency'])->save();
        config(['spending.pricing.models' => []]);
        $batch = $this->batch();
        $preview = $this->study->preview($batch);
        $this->assertNull($preview['estimated']);
        $this->assertNull($preview['minimum_budget']);
        $this->assertFalse($preview['ready']);
        Http::assertNothingSent();
    }

    public function test_expired_or_changed_pricing_invalidates_approval_and_unexecuted_draft_can_be_repriced(): void
    {
        $batch = $this->enabled();
        config(['spending.safety_percent' => 50]);
        app(CostControl::class)->settings()->update(['safety_percent' => 50]);
        $this->blocked(fn () => $this->study->assertApproval($batch->fresh()), 'stale');
        $batch->update(['status' => 'draft']);
        $batch = $this->batch();
        $this->assertNull($batch->pilot_enabled_at);
        $this->assertSame(1173600, $batch->reserved_estimate_micros);
        config(['spending.pricing.valid_until' => now('UTC')->subDay()->format('Y-m-d')]);
        $this->assertFalse($this->study->preview($batch)['ready']);
        Http::assertNothingSent();
    }

    public function test_explicit_plan_and_paid_approval_and_current_fingerprint_are_required(): void
    {
        $batch = $this->batch();
        $this->actingAs($this->admin)->post('/pilot/enable', ['budget' => '2.00', 'fingerprint' => $this->study->fingerprint($batch)])->assertNotFound();
        $this->blocked(fn () => $this->study->enable($batch, $this->admin, 10000, $this->study->fingerprint($batch)), 'Approval requires');
        $this->blocked(fn () => $this->study->enable($batch, $this->admin, 2000000, str_repeat('a', 64)), 'Approval requires');
        Http::assertNothingSent();
    }

    public function test_pilot_ceiling_and_twelve_attempt_cap_are_enforced_before_dispatch(): void
    {
        $batch = $this->enabled();
        $batch->update(['pilot_budget_micros' => 1]);
        $this->fake();
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_edited_batch_counts_or_market_snapshots_cannot_be_enabled(): void
    {
        $batch = $this->batch();
        $item = $batch->items->first();
        $snapshot = $item->market_snapshot;
        $snapshot['country'] = 'US';
        $item->update(['market_snapshot' => $snapshot]);
        $this->blocked(fn () => $this->study->assertConfiguration($batch), 'snapshots or request limits');
        Http::assertNothingSent();
    }

    public function test_request_duplicates_are_prevented_when_an_existing_claim_is_reconciled(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $item = $batch->items()->where('status', 'completed')->first();
        $run = $item->run;
        $item->update(['status' => 'processing', 'claimed_at' => now()->subMinutes(5)]);
        app(BatchProcessor::class)->recover();
        $this->assertSame($run->id, $item->fresh()->test_run_id);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(1, $batch->runs()->count());
        Http::assertSentCount(1);
    }

    public function test_bad_citation_or_answer_integrity_prevents_quality_approval(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $run->response->citations()->first()->update(['url' => 'https://unverified.example.test/']);
        $quality = app(PilotQuality::class)->inspect($run);
        $this->assertFalse($quality['can_approve']);
        $this->blocked(fn () => $this->approve($run), 'Approval requires');
        $this->approve($run, 'flagged');
        $this->assertSame(0, $this->study->report($batch)['provisional']['total']);
    }

    public function test_no_citations_is_an_explicit_warning_but_not_an_invented_official_citation(): void
    {
        $batch = $this->enabled();
        Http::fake(['api.openai.com/*' => fn () => Http::response($this->payload(citations: false))]);
        $this->process();
        $run = $batch->runs()->first();
        $quality = app(PilotQuality::class)->inspect($run);
        $this->assertTrue($quality['can_approve']);
        $this->assertNotEmpty($quality['warnings']);
        $this->approve($run);
        $this->assertSame(0.0, $this->study->report($batch)['summary']['citationRate']);
    }

    public function test_hanging_gardens_is_a_competitor_and_mention_only_is_not_a_recommendation(): void
    {
        $batch = $this->enabled();
        $text = "Recommended resorts:\n1. Hanging Gardens of Bali\n\nNandini Jungle Resort is mentioned for context only, not recommended.";
        $this->fake($text);
        $this->process();
        $run = $batch->runs()->first();
        $this->approve($run);
        $report = $this->study->report($batch);
        $this->assertSame(100.0, $report['summary']['mentionRate']);
        $this->assertSame(0.0, $report['summary']['recommendationRate']);
        $this->assertNull($report['summary']['averagePosition']);
        $this->assertSame('Hanging Gardens of Bali', $report['competitors']->first()['hotel']->name);
    }

    public function test_unapproved_results_do_not_generate_pilot_hypotheses_and_historical_reviews_are_preserved(): void
    {
        $original = Improvement::factory()->create(['status' => 'completed', 'notes' => 'Historical reviewed recommendation']);
        $batch = $this->enabled();
        $this->fake('1. Capella Ubud is recommended.');
        $this->process();
        $this->assertSame(0, app(ImprovementIntelligence::class)->refresh($this->admin, $batch->id));
        $this->approve($batch->runs()->first());
        $count = app(ImprovementIntelligence::class)->refresh($this->admin, $batch->id);
        $this->assertGreaterThan(0, $count);
        $this->assertSame('completed', $original->fresh()->status);
        $this->assertSame('Historical reviewed recommendation', $original->fresh()->notes);
        $this->assertTrue($original->fresh()->active);
        foreach (Improvement::where('detection_key', 'like', 'pilot:%')->get() as $recommendation) {
            $this->assertSame([$batch->runs->first()->id], array_column($recommendation->evidence, 'test_id'));
        }
    }

    public function test_viewers_can_read_pilot_reports_but_cannot_prepare_enable_review_or_generate(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $viewer = User::factory()->create();
        app(TenantContext::class)->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/pilot')->assertOk();
        foreach (['/pilot/prepare', '/pilot/results/'.$run->id.'/review', '/pilot/improvements'] as $url) {
            $this->post($url, [])->assertForbidden();
        }$this->assertSame(0, PilotResultReview::count());
        Http::assertSentCount(1);
    }

    public function test_historical_results_cannot_be_approved_through_pilot_review_routes(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $quality = app(PilotQuality::class)->inspect($run);
        $run->update(['test_batch_id' => null, 'batch_item_id' => null]);
        $this->actingAs($this->admin)->post('/pilot/results/'.$run->id.'/review', ['decision' => 'approved', 'fingerprint' => $quality['fingerprint'], 'acknowledge' => 1])->assertNotFound();
    }

    public function test_quality_review_requires_acknowledgment_flag_reason_and_unchanged_evidence(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $quality = app(PilotQuality::class)->inspect($run);
        $this->blocked(fn () => app(PilotQuality::class)->review($run, $this->admin, 'approved', null, $quality['fingerprint'], false), 'Approval requires');
        $this->blocked(fn () => app(PilotQuality::class)->review($run, $this->admin, 'flagged', 'bad', $quality['fingerprint'], false), 'Approval requires');
        $assessment = $run->response->assessments()->first();
        $assessment->update(['manual' => true, 'recommended' => false]);
        $this->blocked(fn () => app(PilotQuality::class)->review($run->fresh(), $this->admin, 'approved', null, $quality['fingerprint'], true), 'Evidence changed');
    }

    public function test_pilot_report_excludes_unrelated_historical_tests_from_its_denominator(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $this->approve($run);
        $attributes = $run->getAttributes();
        unset($attributes['id'],$attributes['created_at'],$attributes['updated_at'],$attributes['organization_id'],$attributes['monitored_brand_id']);
        $attributes['submission_id'] = (string) Str::uuid();
        $attributes['test_batch_id'] = null;
        $attributes['batch_item_id'] = null;
        $historical = TestRun::create($attributes);
        $historical->response()->create(['text' => 'Historical answer', 'status' => 'completed', 'api_response_id' => 'resp_old', 'raw_metadata' => ['status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed']]]]);
        $this->assertSame(1, $this->study->report($batch)['summary']['total']);
        $this->assertSame(2, app(VisibilityMetrics::class)->runs()->count());
    }

    public function test_existing_daily_cap_pauses_the_pilot_without_increasing_limits(): void
    {
        config(['intelligence.daily_limit' => 1]);
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame(1, config('intelligence.daily_limit'));
        Http::assertSentCount(1);
    }

    public function test_plan_configuration_rejects_branded_questions_or_more_than_one_market(): void
    {
        $prompts = config('pilot.prompts');
        $prompts[0]['text'] = 'Recommend Nandini Jungle Resort';
        config(['pilot.prompts' => $prompts]);
        $this->blocked(fn () => $this->batch(), 'unbranded');
        $this->assertSame(1, SearchPrompt::count());
        Http::assertNothingSent();
    }

    public function test_pilot_review_and_report_cannot_be_accessed_from_another_organization(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $review = $this->approve($run);
        $this->actingAs($this->admin)->post('/organizations', ['organization_name' => 'Other customer', 'name' => 'Other Business', 'official_website' => 'https://other.example.test/', 'industry' => 'Retail', 'country' => 'US', 'aliases' => 'Other Business Store', 'verified' => 1])->assertRedirect();
        $this->get('/pilot')->assertNotFound();
        $this->post('/pilot/prepare')->assertNotFound();
        $this->post('/pilot/results/'.$run->id.'/review', [])->assertNotFound();
        $organization = session('organization_id');
        $brand = session('monitored_brand_id');
        app(TenantContext::class)->set($organization, $brand, $this->admin);
        $this->assertSame(0, PilotResultReview::count());
        Http::assertSentCount(1);
    }

    public function test_bounded_cron_command_restores_tenant_context_for_approved_pilot(): void
    {
        $batch = $this->enabled();
        $org = app(TenantContext::class)->organizationId;
        $brand = app(TenantContext::class)->brandId;
        app(TenantContext::class)->clear();
        $this->fake();
        $this->artisan('intelligence:batches-process', ['--limit' => 1])->expectsOutputToContain('Processed 1')->assertSuccessful();
        $this->assertNull(app(TenantContext::class)->organizationId);
        $this->assertSame($org, DB::table('test_runs')->value('organization_id'));
        $this->assertSame($brand, DB::table('test_runs')->value('monitored_brand_id'));
        Http::assertSentCount(1);
    }

    public function test_saved_answer_corruption_and_duplicate_provider_ids_cannot_enter_final_metrics(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process(2);
        $runs = $batch->runs()->orderBy('id')->get();
        $this->approve($runs[0]);
        $first = $runs[0]->response;
        $second = $runs[1]->response;
        $raw = $second->raw_metadata;
        $raw['id'] = $first->api_response_id;
        $second->update(['api_response_id' => $first->api_response_id, 'raw_metadata' => $raw]);
        $this->assertFalse(app(PilotQuality::class)->inspect($runs[0])['can_approve']);
        $this->assertSame(0, $this->study->report($batch)['summary']['total']);
        $second->update(['text' => 'Altered answer']);
        $quality = app(PilotQuality::class)->inspect($runs[1]);
        $this->assertFalse($quality['checks']['saved_answer_matches_raw']);
        $this->assertSame(0, app(VisibilityMetrics::class)->runs()->count());
    }

    public function test_database_quality_review_link_cannot_point_to_a_different_batch(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->first();
        $other = app(BatchManager::class)->preview($this->admin, ['creation_id' => (string) Str::uuid(), 'name' => 'Other batch', 'prompt_ids' => [SearchPrompt::first()->id], 'market_ids' => [$run->market_scenario_id], 'repetitions' => 1]);
        $this->expectException(QueryException::class);
        DB::table('pilot_result_reviews')->insert(['organization_id' => $run->organization_id, 'monitored_brand_id' => $run->monitored_brand_id, 'test_batch_id' => $other->id, 'test_run_id' => $run->id, 'reviewed_by' => $this->admin->id, 'decision' => 'approved', 'evidence_fingerprint' => str_repeat('a', 64), 'checks' => '{}']);
    }

    public function test_removed_enable_route_cannot_activate_the_pilot(): void
    {
        $batch = $this->batch();
        $this->actingAs($this->admin)->post('/pilot/enable', ['budget' => '2.00', 'fingerprint' => $this->study->fingerprint($batch), 'reviewed_plan' => 1, 'approve_paid' => 1])->assertNotFound();
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->pilot_enabled_at);
        Http::assertNothingSent();
    }

    public function test_approved_budget_cannot_be_used_after_approver_loses_manager_permission(): void
    {
        $batch = $this->enabled();
        DB::table('organization_user')->where('organization_id', $batch->organization_id)->where('user_id', $this->admin->id)->update(['role' => 'viewer']);
        $this->blocked(fn () => $this->study->assertApproval($batch), 'approval is stale');
        $this->fake();
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        Http::assertNothingSent();
    }

    public function test_response_for_a_different_question_cannot_pass_pilot_quality_review(): void
    {
        $batch = $this->enabled();
        $this->fake();
        $this->process();
        $run = $batch->runs()->firstOrFail();
        $run->update(['prompt_text' => 'A different question unrelated to the saved snapshot']);
        $inspection = app(PilotQuality::class)->inspect($run->fresh());
        $this->assertFalse($inspection['can_approve']);
        $this->blocked(fn () => $this->approve($run->fresh()), 'Approval requires');
        Http::assertSentCount(1);
    }
}
