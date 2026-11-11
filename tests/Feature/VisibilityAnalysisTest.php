<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\Hotel;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\CitationAnalysis;
use App\Services\VisibilityAnalysis;
use App\Services\VisibilityMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisibilityAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
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

    private function saved(string $text, string $status = 'completed', array $citations = []): TestRun
    {
        $run = TestRun::create(['user_id' => $this->admin()->id, 'ai_provider_id' => AiProvider::first()->id, 'search_prompt_id' => SearchPrompt::first()->id, 'market_scenario_id' => MarketScenario::first()->id, 'submission_id' => (string) Str::uuid(), 'model' => 'gpt-4.1-mini', 'prompt_version' => 1, 'prompt_text' => 'Best luxury jungle resorts in Ubud Bali', 'market_snapshot' => ['name' => 'Singapore / English'], 'request_settings' => ['tools' => [['type' => 'web_search']]], 'status' => $status, 'executed_at' => now()]);
        $r = $run->response()->create(['text' => $text, 'status' => $status, 'api_response_id' => 'resp_fixture', 'raw_metadata' => ['status' => $status, 'output' => [['type' => 'web_search_call', 'status' => 'completed']]]]);
        $r->citations()->createMany($citations);

        return $run->fresh(['response']);
    }

    private function citation(string $url, string $title = 'Source', ?int $start = 0, ?int $end = 100): array
    {
        return ['url' => $url, 'title' => $title, 'domain' => parse_url($url, PHP_URL_HOST), 'start_index' => $start, 'end_index' => $end, 'is_official' => false];
    }

    public function test_saved_real_test_three_has_six_unranked_recommendations_and_map_citations(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/saved-test-3.json')), true, 512, JSON_THROW_ON_ERROR);
        $run = $this->saved($fixture['text'], 'completed', $fixture['citations']);
        $run->response->update(['raw_metadata' => $fixture['raw_metadata']]);
        $hash = hash('sha256', $run->response->text.json_encode($run->response->raw_metadata));
        $service = app(VisibilityMetrics::class);
        $runs = $service->runs();
        $summary = $service->summary($runs);
        $hotels = $service->hotels($runs);
        $this->assertSame(1, $summary['total']);
        $this->assertEquals(0, $summary['mentionRate']);
        $this->assertEquals(0, $summary['recommendationRate']);
        $this->assertEquals(0, $summary['citationRate']);
        $this->assertNull($summary['averagePosition']);
        $this->assertCount(6, $hotels);
        $this->assertSame(6, $hotels->sum('recommendations'));
        $this->assertSame(6, $hotels->sum('citations'));
        $this->assertSame(0, $hotels->sum('official_citations'));
        $this->assertCount(6, $runs->first()->response->assessments);
        $this->assertCount(0, $run->response->fresh()->assessments);
        $this->assertSame(0, $run->response->fresh()->assessments->whereNotNull('rank')->count());
        $this->assertSame(6, $runs->first()->response->citations->where('classification', 'map_listing')->count());
        $this->assertSame(0, $run->response->fresh()->citations->where('classification', 'map_listing')->count());
        $this->assertSame($hash, hash('sha256', $run->response->fresh()->text.json_encode($run->response->fresh()->raw_metadata)));
        foreach (['/dashboard', '/competitors', '/opportunities', '/hotels', '/tests/'.$run->id, '/tests/'.$run->id.'/analysis'] as $path) {
            $this->actingAs($this->admin())->get($path)->assertOk();
        }
        $this->get('/dashboard')->assertSee('0 / 1 valid tests')->assertSee('Not ranked');
        $this->get('/opportunities')->assertSee('Hypothesis')->assertSee('no official website citation attached');
        Http::assertNothingSent();
    }

    public function test_identity_boundaries_negative_mentions_and_url_duplicates(): void
    {
        $text = "Recommended hotels:\n\n1. Hanging Gardens of Bali\n\nNandini Jungle Resort is not recommended.\n\nNandini is a village name in this context.\n\n**[Capella Ubud](https://example.com/Capella+Ubud)**";
        $result = app(VisibilityAnalysis::class)->detect($text, Hotel::all());
        $rows = collect($result['hotels']);
        $nandini = $rows[Hotel::where('identifier', 'nandini')->value('id')];
        $this->assertFalse($nandini['recommended']);
        $gardens = $rows[Hotel::where('identifier', 'hanging-gardens-of-bali')->value('id')];
        $this->assertTrue($gardens['recommended']);
        $this->assertNull($gardens['rank']);
        $capella = $rows[Hotel::where('identifier', 'capella-ubud')->value('id')];
        $this->assertCount(1, $capella['occurrences']);
        $this->assertNull($capella['rank']);
        $standalone = app(VisibilityAnalysis::class)->detect('Nandini', Hotel::all());
        $this->assertEmpty($standalone['hotels']);
    }

    public function test_recommendation_order_requires_explicit_numbers_and_respects_disclaimers(): void
    {
        $analyzer = app(VisibilityAnalysis::class);
        $hotels = Hotel::all();
        $n = Hotel::where('identifier', 'nandini')->value('id');
        $result = $analyzer->detect("Ranked recommendations (best to worst):\n1. Nandini Jungle Resort\n2. Capella Ubud", $hotels);
        $this->assertTrue($result['hotels'][$n]['recommended']);
        $this->assertSame(1, $result['hotels'][$n]['rank']);
        foreach (["Recommended hotels:\n1. Nandini Jungle Resort\n2. Capella Ubud", "Recommended hotels, in no particular order:\n1. Nandini Jungle Resort\n2. Capella Ubud", "Recommended hotels:\n- Nandini Jungle Resort\n- Capella Ubud"] as $text) {
            $result = $analyzer->detect($text, $hotels);
            $this->assertTrue($result['hotels'][$n]['recommended']);
            $this->assertNull($result['hotels'][$n]['rank']);
        }
        $result = $analyzer->detect("Hotels mentioned only for context:\n1. Nandini Jungle Resort", $hotels);
        $this->assertFalse($result['hotels'][$n]['recommended']);
        $this->assertNull($result['hotels'][$n]['rank']);
    }

    public function test_ambiguous_identity_aliases_and_unverified_headings_are_not_assigned(): void
    {
        Hotel::create(['identifier' => 'ambiguous', 'name' => 'Another verified property', 'aliases' => ['Capella Ubud'], 'official_websites' => ['https://example.com/another'], 'verification_url' => 'https://example.com/another', 'verified_at' => now()]);
        $result = app(VisibilityAnalysis::class)->detect("Recommended hotels:\n\n**Capella Ubud**\n\n**Mystery Jungle Resort**", Hotel::all());
        $this->assertEmpty($result['hotels']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_citation_domains_and_property_paths_are_classified_conservatively(): void
    {
        $run = $this->saved('Hotels for context.');
        $sources = app(CitationAnalysis::class);
        $cases = [
            ['https://WWW.NANDINIBALI.COM./stay?utm_source=ai#rooms', 'nandini_official'],
            ['https://nandinibali.com.attacker.example/', 'other'],
            ['https://booking.com/hotel/id/nandini.html', 'booking_platform'],
            ['https://www.tripadvisor.com/Hotel_Review-example', 'third_party_travel'],
            ['https://www.google.com/maps/search/Nandini', 'map_listing'],
            ['https://www.fourseasons.com/sayan/accommodations/', 'competitor_official'],
            ['https://www.fourseasons.com/jimbaranbay/', 'other'],
            ['https://www.fourseasons.com/sayan-pretend/', 'other'],
            ['https://www.fourseasons.com/sayan/../jimbaranbay/', 'other'],
            ['https://capellahotels.com/en/capella-ubud/rooms', 'competitor_official'],
        ];
        foreach ($cases as [$url,$expected]) {
            $citation = $run->response->citations()->create($this->citation($url));
            $sources->classify($citation, Hotel::all());
            $this->assertSame($expected, $citation->fresh()->classification, $url);
        }
        $this->assertNull($sources->normalize('javascript:alert(1)'));
        $this->assertNull($sources->normalize('https://user:password@nandinibali.com'));
        $this->assertSame('https://nandinibali.com/stay', $sources->normalize('https://www.nandinibali.com/stay?utm_source=test#rooms')['url']);
    }

    public function test_rates_positions_and_distinct_test_counts_match_manual_arithmetic(): void
    {
        $this->saved("Ranked recommendations:\n\n1. Nandini Jungle Resort\n\n2. Capella Ubud. Capella Ubud.", 'completed', [$this->citation('https://nandinibali.com/', 'Nandini', 26, 46)]);
        $this->saved("Recommended hotels:\n\n3. Nandini Jungle Resort");
        $this->saved('Nandini Jungle Resort is mentioned for context only.');
        $this->saved('1. Nandini Jungle Resort is recommended.', 'failed');
        $invalid = $this->saved('1. Nandini Jungle Resort is recommended.');
        $invalid->response->update(['raw_metadata' => ['status' => 'completed', 'output' => []]]);
        $service = app(VisibilityMetrics::class);
        $runs = $service->runs();
        $summary = $service->summary($runs);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(3, $summary['mentioned']);
        $this->assertSame(2, $summary['recommended']);
        $this->assertSame(1, $summary['cited']);
        $this->assertEquals(100, $summary['mentionRate']);
        $this->assertEquals(66.7, $summary['recommendationRate']);
        $this->assertEquals(33.3, $summary['citationRate']);
        $this->assertEquals(1, $summary['averagePosition']);
        $this->assertSame(1, $summary['rankedTests']);
        $capella = $service->hotels($runs)->first(fn ($row) => $row['hotel']->identifier === 'capella-ubud');
        $this->assertSame(1, $capella['mentions']);
        $this->assertSame(1, $capella['recommendations']);
    }

    public function test_manual_correction_preserves_evidence_and_survives_reanalysis(): void
    {
        $text = "Ranked recommendations:\n\n1. Nandini Jungle Resort\n\n2. Capella Ubud";
        $run = $this->saved($text);
        $analyzer = app(VisibilityAnalysis::class);
        $analyzer->analyze($run->response);
        $raw = $run->response->fresh()->raw_metadata;
        $hotel = Hotel::where('identifier', 'nandini')->first();
        $this->actingAs($this->admin())->put('/tests/'.$run->id.'/analysis', ['hotel_id' => $hotel->id, 'decision' => 'mentioned', 'evidence' => '1. Nandini Jungle Resort', 'review_note' => 'Reviewing this as mention only pending further evidence.'])->assertRedirect();
        $run->response->update(['analysis_fingerprint' => null]);
        $analyzer->analyze($run->response->fresh());
        $assessment = $run->response->fresh()->assessments->firstWhere('hotel_id', $hotel->id);
        $this->assertTrue($assessment->manual);
        $this->assertFalse($assessment->recommended);
        $this->assertNotNull($assessment->reviewed_by);
        $this->assertSame($text, $run->response->fresh()->text);
        $this->assertSame($raw, $run->response->fresh()->raw_metadata);
        $this->put('/tests/'.$run->id.'/analysis', ['hotel_id' => $hotel->id, 'decision' => 'recommended', 'rank' => 1, 'evidence' => '1. Nandini Jungle Resort', 'review_note' => 'Numeric position explicitly stated in the original.'])->assertRedirect();
        $this->assertSame(1, $run->response->fresh()->assessments->firstWhere('hotel_id', $hotel->id)->rank);
    }

    public function test_manual_rank_and_cross_response_citation_inventions_are_rejected(): void
    {
        $run = $this->saved('Nandini Jungle Resort is recommended.');
        $other = $this->saved('Capella Ubud is recommended.', 'completed', [$this->citation('https://example.com/')]);
        $hotel = Hotel::where('identifier', 'nandini')->first();
        $this->actingAs($this->admin());
        $data = ['hotel_id' => $hotel->id, 'decision' => 'recommended', 'rank' => 1, 'evidence' => $run->response->text, 'review_note' => 'Trying to supply an unstated position.'];
        $this->put('/tests/'.$run->id.'/analysis', $data)->assertSessionHasErrors('rank');
        unset($data['rank']);
        $data['citation_ids'] = [$other->response->citations->first()->id];
        $this->put('/tests/'.$run->id.'/analysis', $data)->assertSessionHasErrors('citation_ids.0');
        unset($data['citation_ids']);
        $data['evidence'] = 'Invented evidence';
        $this->put('/tests/'.$run->id.'/analysis', $data)->assertSessionHasErrors('evidence');
    }

    public function test_map_citation_cannot_be_manually_marked_official(): void
    {
        $run = $this->saved('Nandini Jungle Resort is recommended.', 'completed', [$this->citation('https://www.google.com/maps/search/Nandini')]);
        $citation = $run->response->citations->first();
        $hotel = Hotel::where('identifier', 'nandini')->first();
        $this->actingAs($this->admin())->put('/tests/'.$run->id.'/citations/'.$citation->id, ['classification' => 'nandini_official', 'hotel_id' => $hotel->id, 'review_note' => 'Maps must not be marked as an official hotel domain.'])->assertSessionHasErrors('classification');
    }

    public function test_identity_updates_require_verified_sources_and_unambiguous_aliases(): void
    {
        $this->actingAs($this->admin());
        $data = ['name' => 'Verified New Resort', 'aliases' => "Verified New Resort\nNew Resort Bali", 'official_websites' => 'https://example.com/new-resort', 'verification_url' => 'https://example.com/new-resort', 'verified' => 1];
        $this->post('/hotels', $data)->assertRedirect('/hotels');
        $this->assertDatabaseHas('hotels', ['name' => 'Verified New Resort']);
        $data['name'] = 'Conflicting Hotel';
        $data['aliases'] = 'Capella Ubud';
        $this->post('/hotels', $data)->assertSessionHasErrors('aliases');
        $data['aliases'] = 'Another Resort';
        $data['official_websites'] = 'https://booking.com/hotel/id/another';
        $this->post('/hotels', $data)->assertSessionHasErrors('official_websites');
        $data['official_websites'] = 'https://example.com/another';
        $data['verification_url'] = 'javascript:alert(1)';
        $this->post('/hotels', $data)->assertSessionHasErrors('verification_url');
    }

    public function test_analysis_routes_are_administrator_only_and_failed_tests_cannot_be_reviewed(): void
    {
        foreach (['/competitors', '/opportunities', '/hotels'] as $route) {
            $this->get($route)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create())->get('/competitors')->assertForbidden();
        $failed = $this->saved('Nandini Jungle Resort', 'failed');
        $this->actingAs($this->admin())->get('/tests/'.$failed->id.'/analysis')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_paragraph_citations_are_linked_only_when_the_property_context_is_unambiguous(): void
    {
        $text = "Recommended hotels:\n\nNandini Jungle Resort has a jungle setting. Source.";
        $run = $this->saved($text, 'completed', [$this->citation('https://example.com/travel', 'Travel', mb_strpos($text, 'Source'), mb_strlen($text))]);
        app(VisibilityAnalysis::class)->analyze($run->response);
        $this->assertCount(1, $run->response->fresh()->assessments->first()->supporting_citation_ids);
        $text = 'Nandini Jungle Resort and Capella Ubud are recommended. Source.';
        $run = $this->saved($text, 'completed', [$this->citation('https://example.com/travel', 'Travel', mb_strpos($text, 'Source'), mb_strlen($text))]);
        app(VisibilityAnalysis::class)->analyze($run->response);
        foreach ($run->response->fresh()->assessments as $assessment) {
            $this->assertSame([], $assessment->supporting_citation_ids);
        }
    }

    public function test_short_hotel_alias_in_a_non_hotel_context_is_unresolved(): void
    {
        $result = app(VisibilityAnalysis::class)->detect('Mandapa is a pavilion, not a hotel recommendation.', Hotel::all());
        $this->assertEmpty($result['hotels']);
        $this->assertNotEmpty($result['unresolved']);
    }
}
