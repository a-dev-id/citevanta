<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Hotel;
use App\Models\MarketScenario;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\CitationAnalysis;
use App\Services\TenantContext;
use App\Services\VisibilityAnalysis;
use App\Services\VisibilityMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResultClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hotel $brand;

    private Hotel $competitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_id' => $this->owner->id, 'plan_key' => 'pro', 'is_test_fixture' => true]);
        $id = DB::table('monitored_brands')->insertGetId(['organization_id' => $organization->id, 'name' => 'Aurora Ledger', 'short_name' => 'Aurora Ledger', 'slug' => 'aurora-ledger', 'official_website' => 'https://software.example/apps/aurora', 'description' => 'Software business classification fixture', 'industry' => 'Software', 'country' => 'AU', 'aliases' => '[]', 'verified_pages' => '[]', 'verified_at' => now(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(TenantContext::class)->set($organization->id, $id, $this->owner);
        $this->brand = Hotel::create(['identifier' => 'aurora-ledger', 'name' => 'Aurora Ledger', 'aliases' => [], 'official_websites' => ['https://software.example/apps/aurora'], 'verification_url' => 'https://software.example/apps/aurora', 'verified_at' => now()]);
        $this->competitor = Hotel::create(['identifier' => 'summit-dental', 'name' => 'Summit Dental', 'aliases' => [], 'official_websites' => ['https://summit.example'], 'verification_url' => 'https://summit.example', 'verified_at' => now()]);
        SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Business comparison fixture', 'text' => 'Compare verified local businesses', 'version' => 1, 'active' => true]);
        MarketScenario::create(['name' => 'Australia / English', 'country' => 'AU', 'language' => 'English', 'search_settings' => ['type' => 'approximate', 'country' => 'AU']]);
        $this->actingAs($this->owner)->withSession(['organization_id' => $organization->id, 'monitored_brand_id' => $id]);
    }

    private function saved(string $text, array $citations = []): TestRun
    {
        $run = TestRun::create(['is_simulated' => true, 'user_id' => $this->owner->id, 'ai_provider_id' => AiProvider::where('identifier', 'openai')->firstOrFail()->id, 'search_prompt_id' => SearchPrompt::firstOrFail()->id, 'market_scenario_id' => MarketScenario::firstOrFail()->id, 'submission_id' => (string) Str::uuid(), 'model' => 'simulated-classification-fixture', 'prompt_text' => 'Compare verified local businesses', 'prompt_version' => 1, 'market_snapshot' => ['name' => 'Australia / English'], 'request_settings' => ['simulated' => true], 'status' => 'completed', 'repetition' => 1, 'executed_at' => now()]);
        $response = $run->response()->create(['text' => $text, 'status' => 'completed', 'raw_metadata' => ['simulated' => true, 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed']]]]);
        foreach ($citations as $url) {
            $response->citations()->create(['url' => $url, 'title' => 'Saved source', 'domain' => parse_url($url, PHP_URL_HOST), 'start_index' => 0, 'end_index' => mb_strlen($text), 'is_official' => false]);
        }

        return $run->fresh(['response']);
    }

    public static function rankingCases(): array
    {
        return [
            'ranking note without a comparison' => ["Rankings may vary.\nRecommended businesses:\n1. Aurora Ledger\n2. Summit Dental", true, null],
            'negative ranking statement' => ["These recommendations are not a comparative ranking:\n1. Aurora Ledger is recommended.\n2. Summit Dental is recommended.", true, null],
            'conflicting positions' => ["Ranking:\n1. Aurora Ledger is recommended.\n2. Aurora Ledger is recommended.\n3. Aurora Ledger is recommended.\n4. Summit Dental is recommended.", true, null],
            'single numbered recommendation' => ['1. Aurora Ledger is recommended.', true, null],
            'ordinary numbered list' => ["Recommended businesses:\n1. Aurora Ledger\n2. Summit Dental", true, null],
            'explicit comparison' => ["Ranked from best to worst:\n1. Aurora Ledger is recommended.\n2. Summit Dental is recommended.", true, 1],
            'single ranking heading' => ["Ranking:\n1. Aurora Ledger is recommended.", true, null],
            'unordered recommendations' => ["Recommended businesses:\n- Aurora Ledger\n- Summit Dental", true, null],
            'numbering disclaimer' => ["Ranking, numbering only for reference:\n1. Aurora Ledger is recommended.\n2. Summit Dental is recommended.", true, null],
            'alphabetical disclaimer' => ["Ranked businesses, alphabetical order:\n1. Aurora Ledger is recommended.\n2. Summit Dental is recommended.", true, null],
            'mentions' => ['Aurora Ledger is mentioned for context only.', false, null],
            'citation without endorsement' => ['Aurora Ledger is cited as a source only.', false, null],
            'different sentence recommendations' => ['Aurora Ledger is not recommended. Summit Dental is recommended.', false, null],
            'different clause recommendations' => ['Aurora Ledger is mentioned only; Summit Dental is recommended.', false, null],
            'shared recommendation' => ['Aurora Ledger and Summit Dental are recommended.', true, null],
            'unranked top list' => ["Best business options:\n1. Aurora Ledger\n2. Summit Dental", true, null],
        ];
    }

    #[DataProvider('rankingCases')]
    public function test_ranking_requires_comparative_evidence_and_signals_remain_separate(string $text, bool $recommended, ?int $rank): void
    {
        $findings = app(VisibilityAnalysis::class)->detect($text, Hotel::all())['hotels'];
        $this->assertTrue($findings[$this->brand->id]['mentioned']);
        $this->assertSame($recommended, $findings[$this->brand->id]['recommended']);
        $this->assertSame($rank, $findings[$this->brand->id]['rank']);
        if (str_contains($text, 'Summit Dental is recommended')) {
            $this->assertTrue($findings[$this->competitor->id]['recommended']);
        }
    }

    public function test_result_rendering_replaces_stale_automatic_rank_without_rewriting_any_saved_record(): void
    {
        $run = $this->saved('SIMULATED TEST RESULT'."\n\n1. Aurora Ledger is recommended.", ['https://software.example/apps/aurora/overview']);
        $run->response->assessments()->create(['hotel_id' => $this->brand->id, 'mentioned' => true, 'recommended' => true, 'rank' => 1, 'evidence' => $run->response->text, 'occurrences' => [], 'supporting_citation_ids' => [], 'manual' => false, 'rule' => 'legacy-numbering']);
        $snapshot = [];
        foreach (['test_runs', 'ai_responses', 'citations', 'hotel_assessments'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $page = $this->get('/tests/'.$run->id)->assertOk()->assertSee('Brand visibility analysis')->assertSee('Monitored brand')->assertSee('Not ranked')->assertSee('SIMULATED TEST DATA')->assertDontSee('->user()')->assertDontSee('is_platform_admin')->assertDontSee('Hotel visibility analysis')->assertDontSee('Verified hotel');
        $this->assertStringContainsString('data-test-details', $page->getContent());
        $this->assertStringContainsString('href="#response-evidence"', $page->getContent());
        $source = $run->response->citations->first();
        $this->assertStringContainsString('href="#citation-source-'.$source->id.'"', $page->getContent());
        foreach (['/history', '/dashboard', '/competitors', '/tests/'.$run->id.'/analysis'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('->user()');
        }
        foreach ($snapshot as $table => $before) {
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson(), $table.' must remain unchanged');
        }
        $this->assertSame(1, $run->response->fresh()->assessments->first()->rank);
    }

    public function test_official_citation_is_independent_from_mention_and_recommendation(): void
    {
        $run = $this->saved('Use the published documentation as a source.', ['https://software.example/apps/aurora/docs']);
        app(VisibilityAnalysis::class)->preview($run->response);
        $this->assertTrue($run->response->citations->first()->is_official);
        $this->assertCount(0, $run->response->assessments);
        $metrics = app(VisibilityMetrics::class);
        $summary = $metrics->summary($metrics->runs());
        $this->assertSame(0, $summary['mentioned']);
        $this->assertSame(0, $summary['recommended']);
        $this->assertSame(1, $summary['cited']);
        $this->get('/tests/'.$run->id)->assertOk()->assertSee('Monitored brand official');
        Http::assertNothingSent();
    }

    public function test_domains_paths_competitor_and_third_party_sources_are_classified_conservatively(): void
    {
        $run = $this->saved('Aurora Ledger is cited as a reference only.');
        foreach (['https://software.example/apps/aurora/help' => ['nandini_official', true], 'https://software.example/apps/aurora-spoof' => ['other', false], 'https://software.example.attacker.test/apps/aurora' => ['other', false], 'https://summit.example/care' => ['competitor_official', false], 'https://review.example/article' => ['other', false]] as $url => $expected) {
            $source = $run->response->citations()->create(['url' => $url, 'title' => 'Mock source', 'domain' => parse_url($url, PHP_URL_HOST), 'is_official' => false]);
            app(CitationAnalysis::class)->classify($source, Hotel::all());
            $this->assertSame($expected, [$source->classification, $source->is_official]);
        }
        app(VisibilityAnalysis::class)->preview($run->response->fresh());
        $detected = app(VisibilityAnalysis::class)->detect($run->response->text, Hotel::all());
        $this->assertFalse($detected['hotels'][$this->brand->id]['recommended']);
    }

    public function test_viewers_get_read_only_findings_and_cannot_submit_corrections(): void
    {
        $run = $this->saved('Aurora Ledger is recommended.');
        $viewer = User::factory()->create();
        app(TenantContext::class)->organization()->members()->attach($viewer->id, ['role' => 'viewer']);
        $this->actingAs($viewer);
        $this->get('/tests/'.$run->id)->assertOk()->assertDontSee('Review / correct')->assertDontSee('is_platform_admin');
        $this->put('/tests/'.$run->id.'/analysis', ['hotel_id' => $this->brand->id, 'decision' => 'recommended'])->assertForbidden();
    }

    public function test_manual_single_item_rank_is_rejected_even_with_a_numbered_excerpt(): void
    {
        $run = $this->saved('1. Aurora Ledger is recommended.');
        $this->put('/tests/'.$run->id.'/analysis', ['hotel_id' => $this->brand->id, 'decision' => 'recommended', 'rank' => 1, 'evidence' => $run->response->text, 'review_note' => 'Numbering is not comparative ranking.'])->assertSessionHasErrors('rank');
    }

    public function test_unverified_non_hotel_heading_is_reported_as_an_unresolved_business(): void
    {
        $result = app(VisibilityAnalysis::class)->detect("Recommended businesses:\n\n**Unknown Dental Practice**", Hotel::all());
        $this->assertEmpty($result['hotels']);
        $this->assertNotEmpty($result['unresolved']);
        $this->assertStringNotContainsString('hotel identity', $result['unresolved'][0]['reason']);
    }

    public function test_invalid_citation_spans_do_not_become_supporting_evidence(): void
    {
        $run = $this->saved('Aurora Ledger is recommended.', ['https://review.example/source']);
        $run->response->citations()->update(['start_index' => 0, 'end_index' => 999]);
        $response = $run->response->fresh();
        app(VisibilityAnalysis::class)->preview($response);
        $this->assertSame([], $response->assessments->first()->supporting_citation_ids);
    }

    public function test_manual_official_label_cannot_reassign_a_competitor_citation_to_the_monitored_brand(): void
    {
        $run = $this->saved('Aurora Ledger is mentioned for context only.', ['https://summit.example']);
        $source = $run->response->citations->first();
        $source->fill(['classification' => 'nandini_official', 'hotel_id' => $this->competitor->id, 'is_official' => true, 'manual' => true]);
        app(CitationAnalysis::class)->classify($source, Hotel::all(), false);
        $this->assertSame('unverified', $source->classification);
        $this->assertFalse($source->is_official);
    }
}
