<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\CostEntry;
use App\Models\Hotel;
use App\Models\Improvement;
use App\Models\ImprovementExplanation;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\CostControl;
use App\Services\ImprovementExplainer;
use App\Services\ImprovementIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImprovementIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($this->admin);
        config(['intelligence.openai_key' => 'mock-only-key']);
        Http::preventStrayRequests();
    }

    private function saved(string $text = '1. Capella Ubud is recommended.', string $status = 'completed', ?SearchPrompt $prompt = null, array $citations = []): TestRun
    {
        $run = TestRun::create(['user_id' => $this->admin->id, 'ai_provider_id' => AiProvider::first()->id, 'search_prompt_id' => ($prompt ?? SearchPrompt::first())->id, 'market_scenario_id' => MarketScenario::first()->id, 'submission_id' => (string) Str::uuid(), 'model' => 'gpt-4.1-mini', 'prompt_version' => 1, 'prompt_text' => ($prompt ?? SearchPrompt::first())->text, 'market_snapshot' => ['name' => 'Australia / English'], 'request_settings' => [], 'status' => $status, 'executed_at' => now()]);
        $response = $run->response()->create(['text' => $text, 'status' => $status, 'raw_metadata' => ['status' => $status, 'output' => [['type' => 'web_search_call', 'status' => 'completed']]], 'api_response_id' => 'resp_saved']);
        $response->citations()->createMany($citations);

        return $run;
    }

    private function generate(): Improvement
    {
        app(ImprovementIntelligence::class)->refresh($this->admin);

        return Improvement::where('category', 'Search intent coverage')->firstOrFail();
    }

    private function allowSpending(): void
    {
        app(CostControl::class)->settings()->update(['monthly_limit_micros' => 5000000]);
        app(CostControl::class)->month()->update(['opening_micros' => 100000]);
    }

    private function fakeExplanation(Improvement $record, ?array $selection = null, bool $usage = true): void
    {
        $selection ??= ['test_ids' => array_column($record->evidence, 'test_id'), 'action_ids' => array_keys($record->actions)];
        $payload = ['id' => 'resp_explain_mock', 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($selection)]]]]];
        if ($usage) {
            $payload['usage'] = ['input_tokens' => 1000, 'output_tokens' => 100, 'total_tokens' => 1100];
        }
        Http::fake(['api.openai.com/*' => Http::response($payload)]);
    }

    private function explain(Improvement $record, ?string $submission = null): ImprovementExplanation
    {
        $preview = app(ImprovementExplainer::class)->preview($record->fresh());

        return app(ImprovementExplainer::class)->execute($record->fresh(), $this->admin, $submission ?? (string) Str::uuid(), $preview['fingerprint']);
    }

    public function test_brand_assets_and_all_existing_admin_pages_keep_property_identity_separate(): void
    {
        $run = $this->saved();
        $login = $this->get('/login')->assertOk()->assertSee('CiteVanta')->assertSee('Know Where AI Recommends You.')->assertSee('images/citevanta-wordmark.png');
        $login->assertDontSee('Nandini AI Intelligence');
        foreach (['/dashboard', '/tests', '/prompts', '/history', '/settings', '/competitors', '/opportunities', '/hotels', '/batches', '/batches/create', '/improvements', '/tests/'.$run->id, '/tests/'.$run->id.'/analysis'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('CiteVanta')->assertSee('Current Workspace: Nandini Jungle by Hanging Gardens')->assertSee('images/citevanta-icon.png')->assertDontSee('Nandini AI Intelligence');
        }
        $this->assertSame('CiteVanta', config('app.name'));
        foreach (['icon', 'wordmark'] as $asset) {
            $size = getimagesize(public_path(config('branding.'.$asset)));
            $this->assertSame('image/png', $size['mime']);
        }
        Http::assertNothingSent();
    }

    public function test_generation_uses_only_valid_completed_tests_and_is_idempotent(): void
    {
        $valid = $this->saved();
        $this->saved('Nandini Jungle Resort', 'failed');
        $this->saved('Nandini Jungle Resort', 'incomplete');
        $invalid = $this->saved(' ');
        $record = $this->generate();
        $this->assertSame([$valid->id], array_column($record->evidence, 'test_id'));
        $this->assertSame('low', $record->priority);
        $this->assertSame('low', $record->confidence);
        $this->assertStringContainsString('hypotheses', $record->explanation);
        $this->assertSame('https://nandinibali.com/ubud-jungle-resort-in-bali', Improvement::where('category', 'Website content improvements')->first()->verified_target_url);
        $count = Improvement::count();
        app(ImprovementIntelligence::class)->refresh($this->admin);
        $this->assertSame($count, Improvement::count());
        $this->assertSame(1, $record->events()->count());
        Http::assertNothingSent();
    }

    public function test_competitors_and_attached_official_citations_are_traceable(): void
    {
        $text = '1. Capella Ubud is recommended. Source.';
        $run = $this->saved($text, citations: [['url' => 'https://capellahotels.com/en/capella-ubud', 'domain' => 'capellahotels.com', 'title' => 'Capella', 'start_index' => 3, 'end_index' => mb_strlen($text)]]);
        $record = $this->generate();
        $competitor = Improvement::where('category', 'Competitor positioning')->firstOrFail();
        $this->assertTrue($competitor->evidence[0]['competitors'][0]['official_cited']);
        $this->assertSame('Capella Ubud', $competitor->evidence[0]['competitors'][0]['name']);
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertOk()->assertSee(route('tests.show', $run))->assertSee('Suggested action')->assertSee('Hypothesis');
    }

    public function test_mentions_are_not_recommendations_and_missing_citations_get_a_separate_review(): void
    {
        $this->saved('Nandini Jungle Resort is mentioned for context only, not recommended.');
        app(ImprovementIntelligence::class)->refresh($this->admin);
        $this->assertSame(0, Improvement::where('category', 'Search intent coverage')->count());
        $this->assertSame(1, Improvement::where('category', 'Citation opportunities')->count());
        $this->assertSame(1, Improvement::where('category', 'Brand information consistency')->count());
        $this->assertStringContainsString('does not prove inconsistency', Improvement::where('category', 'Brand information consistency')->first()->actions['identity']);
    }

    public function test_official_nandini_citations_do_not_create_false_citation_gaps(): void
    {
        $text = '1. Nandini Jungle Resort is recommended. Source.';
        $this->saved($text, citations: [['url' => 'https://nandinibali.com/', 'domain' => 'nandinibali.com', 'title' => 'Nandini', 'start_index' => 3, 'end_index' => mb_strlen($text)]]);
        $this->assertSame(0, app(ImprovementIntelligence::class)->refresh($this->admin));
        $this->assertSame(0, Improvement::count());
    }

    public function test_hanging_gardens_is_not_confused_with_the_monitored_property(): void
    {
        $this->saved('1. Hanging Gardens of Bali is recommended.');
        $record = $this->generate();
        $this->assertFalse($record->evidence[0]['mentioned']);
        $this->assertSame('Hanging Gardens of Bali', $record->evidence[0]['competitors'][0]['name']);
    }

    public function test_repeated_category_weakness_requires_multiple_prompts(): void
    {
        $this->saved();
        $this->saved();
        $this->saved();
        $record = $this->generate();
        $this->assertSame('medium', $record->priority);
        $this->assertSame(0, Improvement::where('category', 'AI search discoverability')->count());
        $other = SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => SearchPrompt::first()->category, 'text' => 'Luxury stays in Ubud for couples', 'version' => 1, 'active' => true]);
        $this->saved(prompt: $other);
        app(ImprovementIntelligence::class)->refresh($this->admin);
        $repeated = Improvement::where('category', 'AI search discoverability')->firstOrFail();
        $this->assertCount(4, $repeated->evidence);
        $this->assertSame('high', $repeated->priority);
    }

    public function test_manual_corrections_original_text_and_review_decisions_survive_refresh(): void
    {
        $run = $this->saved();
        $record = $this->generate();
        $original = $run->response->fresh()->only(['text', 'raw_metadata', 'api_response_id']);
        $this->actingAs($this->admin)->put('/improvements/'.$record->id, ['status' => 'completed', 'notes' => 'Reviewed with the property team', 'target_url' => 'https://nandinibali.com/'])->assertRedirect();
        $this->assertNotNull($record->fresh()->completed_at);
        $nandini = Hotel::where('identifier', 'nandini')->firstOrFail();
        $run->response->assessments()->create(['hotel_id' => $nandini->id, 'mentioned' => true, 'recommended' => true, 'manual' => true, 'occurrences' => [], 'supporting_citation_ids' => [], 'evidence' => 'Administrator verified identity', 'rule' => 'manual']);
        app(ImprovementIntelligence::class)->refresh($this->admin);
        $this->assertFalse($record->fresh()->active);
        $this->assertSame('completed', $record->fresh()->status);
        $this->assertSame('Reviewed with the property team', $record->fresh()->notes);
        $this->assertSame(2, $record->events()->count());
        $this->assertTrue($run->response->fresh()->assessments->firstWhere('hotel_id', $nandini->id)->manual);
        $this->assertSame($original, $run->response->fresh()->only(['text', 'raw_metadata', 'api_response_id']));
    }

    public function test_status_history_completion_reopening_and_safe_target_validation(): void
    {
        $record = Improvement::factory()->create();
        foreach (['under_review', 'approved', 'in_progress', 'completed', 'dismissed', 'new'] as $status) {
            $this->actingAs($this->admin)->put('/improvements/'.$record->id, ['status' => $status, 'notes' => 'Updated '.$status])->assertRedirect();
            $this->assertSame($status, $record->fresh()->status);
            $this->assertSame($status === 'completed', $record->fresh()->completed_at !== null);
        }
        $this->assertSame(6, $record->events()->count());
        foreach (['javascript:alert(1)', 'https://user:password@example.com/'] as $url) {
            $this->put('/improvements/'.$record->id, ['status' => 'new', 'target_url' => $url])->assertSessionHasErrors('target_url');
        }
        $this->put('/improvements/'.$record->id, ['status' => 'invented'])->assertSessionHasErrors('status');
    }

    public function test_authentication_and_workspace_scoping_prevent_other_property_record_access(): void
    {
        $other = Improvement::factory()->create(['hotel_id' => Hotel::where('identifier', 'capella-ubud')->value('id')]);
        foreach (['/improvements', '/improvements/'.$other->id] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/improvements/refresh')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/improvements')->assertForbidden();
        $this->actingAs($this->admin)->get('/improvements/'.$other->id)->assertNotFound();
        $this->put('/improvements/'.$other->id, ['status' => 'approved'])->assertNotFound();
        $this->post('/improvements/'.$other->id.'/explain', [])->assertNotFound();
        $this->get('/improvements')->assertDontSee($other->title);
        Http::assertNothingSent();
    }

    public function test_zero_limit_blocks_explanation_without_dispatch_or_usage_records(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        app(CostControl::class)->settings()->update(['monthly_limit_micros' => 0]);
        $this->fakeExplanation($record);
        try {
            $this->explain($record);
            $this->fail('Budget must block dispatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('spending limit', $e->getMessage());
        }
        $this->assertSame(0, ImprovementExplanation::count());
        $this->assertSame(0, CostEntry::count());
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertOk()->assertSee('disabled', false);
        Http::assertNothingSent();
    }

    public function test_mocked_ai_explanation_reuses_provider_and_monthly_ledger_without_polluting_visibility_tests(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $result = $this->explain($record);
        $this->assertSame('completed', $result->status);
        $this->assertStringContainsString('AI-generated', $result->text);
        $this->assertSame(1100, $result->usage['total_tokens']);
        $this->assertSame('settled', $result->cost->status);
        $this->assertSame(560, $result->cost->usage_cost_micros);
        $this->assertSame(1, TestRun::count());
        $this->assertSame($result->cost->committed_micros, app(CostControl::class)->balance()['committed']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && ! isset($r['tools']) && $r['text']['format']['strict'] === true && $r['store'] === false);
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertOk()->assertSee('AI-generated explanation')->assertDontSee('1100 tokens');
    }

    public function test_explanation_duplicate_submission_does_not_send_again(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $id = (string) Str::uuid();
        $first = $this->explain($record, $id);
        $second = $this->explain($record, $id);
        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_ai_fabricated_evidence_and_free_form_claims_are_not_displayed(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record, ['test_ids' => [999999], 'action_ids' => ['coverage'], 'claim' => 'Competitor has better backlinks']);
        $result = $this->explain($record);
        $this->assertSame('rejected', $result->status);
        $this->assertNull($result->text);
        $this->assertSame('settled', $result->cost->status);
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertDontSee('better backlinks');
        Http::assertSentCount(1);
    }

    public function test_unknown_usage_keeps_conservative_reservation_and_explicit_http_rejection_releases_it(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $payload = ['id' => 'resp_no_usage', 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['test_ids' => array_column($record->evidence, 'test_id'), 'action_ids' => array_keys($record->actions)])]]]]];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($payload)->push(['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota']], 429)]);
        $result = $this->explain($record);
        $this->assertSame('uncertain', $result->cost->status);
        $this->assertSame($result->cost->reserved_micros, $result->cost->committed_micros);
        $failed = $this->explain($record);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('released', $failed->cost->status);
        $this->assertSame(0, $failed->cost->committed_micros);
    }

    public function test_unknown_interrupted_request_is_not_retried_automatically(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $result = $this->explain($record);
        $this->assertSame('interrupted', $result->status);
        $this->assertSame('uncertain', $result->cost->status);
        try {
            $this->explain($record);
            $this->fail('Unknown outcome must block another request.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unknown outcome', $e->getMessage());
        }
        $this->assertSame(1, ImprovementExplanation::count());
    }

    public function test_changed_evidence_pricing_and_shared_execution_lock_block_dispatch(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $preview = app(ImprovementExplainer::class)->preview($record);
        $record->update(['actions' => ['coverage' => 'Changed verified review action.']]);
        try {
            app(ImprovementExplainer::class)->execute($record->fresh(), $this->admin, (string) Str::uuid(), $preview['fingerprint']);
            $this->fail('Stale preview.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }
        $lock = Cache::lock('intelligence:execution', 120);
        $lock->get();
        try {
            $this->explain($record);
            $this->fail('Shared request lock.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('in progress', $e->getMessage());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_explanation_route_requires_explicit_approval_and_session_issued_preview(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertOk();
        $preview = session('improvement_previews.'.$record->id);
        $this->post('/improvements/'.$record->id.'/explain', ['submission_id' => $preview['submission']])->assertSessionHasErrors('acknowledge');
        $this->post('/improvements/'.$record->id.'/explain', ['submission_id' => (string) Str::uuid(), 'acknowledge' => 1])->assertStatus(419);
        Http::assertNothingSent();
        $this->travel(61)->seconds();
        $this->post('/improvements/'.$record->id.'/explain', ['submission_id' => $preview['submission'], 'acknowledge' => 1])->assertRedirect();
        $this->assertSame(1, ImprovementExplanation::count());
    }

    public function test_no_verified_page_is_invented_for_unrelated_search_intent(): void
    {
        $prompt = SearchPrompt::first();
        $prompt->update(['text' => 'Accessible beach hotels near Sanur']);
        $this->saved(prompt: $prompt);
        $record = $this->generate();
        $this->assertSame(0, Improvement::where('category', 'Website content improvements')->count());
        $this->assertNull($record->verified_target_url);
        Http::assertNothingSent();
    }

    public function test_dashboard_counts_real_tracked_recommendations_for_current_workspace_only(): void
    {
        Improvement::factory()->create(['priority' => 'high', 'status' => 'in_progress']);
        Improvement::factory()->create(['status' => 'completed', 'completed_at' => now()]);
        Improvement::factory()->create(['status' => 'dismissed']);
        Improvement::factory()->create(['hotel_id' => Hotel::where('identifier', 'capella-ubud')->value('id'), 'status' => 'in_progress']);
        $summary = app(ImprovementIntelligence::class)->summary();
        $this->assertSame(1, $summary['open']);
        $this->assertSame(1, $summary['high']);
        $this->assertSame(1, $summary['in_progress']);
        $this->assertSame(1, $summary['completed']);
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Improvement Opportunities');
    }

    public function test_explanation_pricing_expiry_fails_closed_without_an_api_call(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        config(['spending.pricing.valid_until' => now('UTC')->subDay()->format('Y-m-d')]);
        try {
            $this->explain($record);
            $this->fail('Expired prices must block.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pricing estimate is unavailable', $e->getMessage());
        }
        $this->assertSame(0, ImprovementExplanation::count());
        Http::assertNothingSent();
    }

    public function test_explanation_commitments_reduce_the_shared_monthly_budget(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $first = $this->explain($record);
        $preview = app(ImprovementExplainer::class)->preview($record);
        $used = app(CostControl::class)->balance()['used'];
        app(CostControl::class)->settings()->update(['monthly_limit_micros' => $used + $preview['quote']['reserve'] - 1]);
        try {
            $this->explain($record);
            $this->fail('Safety reservation must fit.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('spending limit', $e->getMessage());
        }
        $this->assertSame(1, CostEntry::count());
        $this->assertSame(1, ImprovementExplanation::count());
        Http::assertSentCount(1);
    }

    public function test_generated_explanations_keep_the_original_evidence_snapshot_after_refresh(): void
    {
        $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->fakeExplanation($record);
        $first = $this->explain($record);
        $original = $first->only(['evidence_snapshot', 'text', 'raw_metadata', 'usage']);
        $this->saved();
        app(ImprovementIntelligence::class)->refresh($this->admin);
        $this->assertCount(2, $record->fresh()->evidence);
        $this->assertSame($original, $first->fresh()->only(['evidence_snapshot', 'text', 'raw_metadata', 'usage']));
        Http::assertSentCount(1);
    }

    public function test_a_manual_evidence_correction_invalidates_an_approved_explanation_preview(): void
    {
        $run = $this->saved();
        $record = $this->generate();
        $this->allowSpending();
        $this->actingAs($this->admin)->get('/improvements/'.$record->id)->assertOk();
        $preview = session('improvement_previews.'.$record->id);
        $run->response->assessments()->create(['hotel_id' => Hotel::where('identifier', 'nandini')->value('id'), 'mentioned' => true, 'recommended' => true, 'manual' => true, 'occurrences' => [], 'supporting_citation_ids' => [], 'evidence' => 'Confirmed by administrator', 'rule' => 'manual']);
        $this->post('/improvements/'.$record->id.'/explain', ['submission_id' => $preview['submission'], 'acknowledge' => 1])->assertSessionHasErrors('explanation');
        $this->assertSame(0, ImprovementExplanation::count());
        Http::assertNothingSent();
    }

    public function test_unresolved_monitored_name_is_not_treated_as_confirmed_absence(): void
    {
        $this->saved('Nandini is mentioned without a verified property identity.');
        $this->assertSame(0, app(ImprovementIntelligence::class)->refresh($this->admin));
        $this->assertSame(0, Improvement::count());
        $other = Hotel::where('identifier', 'capella-ubud')->firstOrFail();
        $other->update(['aliases' => ['Nandini Jungle Resort']]);
        $this->saved('1. Nandini Jungle Resort is recommended.');
        $this->assertSame(0, app(ImprovementIntelligence::class)->refresh($this->admin));
        Http::assertNothingSent();
    }
}
