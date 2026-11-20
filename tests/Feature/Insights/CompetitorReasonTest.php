<?php

namespace Tests\Feature\Insights;

use App\Enums\AiPurpose;
use App\Enums\HotelHasFeature;
use App\Models\CompetitorInsight;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ResponseAnalysis;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Insights\Competitors\CompetitorReason;
use App\Services\Insights\Competitors\CompetitorReasonResult;
use App\Services\Insights\Competitors\CompetitorReasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class CompetitorReasonTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /** Alaya (the hotel) with competitors Kayon Jungle Resort (kayonresort.com) and Maya Ubud Resort. */
    private function hotel(): Hotel
    {
        return $this->scannableHotel(prompts: 0);
    }

    /**
     * @param  list<string>  $competitors
     * @param  list<string>  $domains
     */
    private function answer(ScanRun $scan, Prompt $prompt, string $text, bool $hotelMentioned, array $competitors, array $domains = []): ScanResponse
    {
        $response = ScanResponse::factory()->for($scan, 'scanRun')->for($prompt)->create(['answer_text' => $text]);
        ResponseAnalysis::factory()->for($response, 'response')->create([
            'hotel_mentioned' => $hotelMentioned,
            'position' => $hotelMentioned ? 1 : null,
            'competitors_mentioned' => $competitors,
        ]);
        foreach ($domains as $domain) {
            $response->citations()->create(['url' => "https://{$domain}/page", 'domain' => $domain]);
        }

        return $response;
    }

    public function test_only_prompts_where_a_competitor_beat_the_hotel_are_explained_most_wins_first(): void
    {
        $hotel = $this->hotel();
        $scan = ScanRun::factory()->for($hotel)->create();
        [$notMentioned, $rankedLower, $hotelFirst, $noCompetitor] = Prompt::factory()->for($hotel)->count(4)->create()->all();

        $this->answer($scan, $notMentioned, '1. **Kayon Jungle Resort** - kids club', false, ['Kayon Jungle Resort']);
        $this->answer($scan, $rankedLower, '1. **Maya Ubud Resort** - spa. 2. **Alaya Resort Ubud** - pool', true, ['Maya Ubud Resort']);
        $this->answer($scan, $rankedLower, '1. **Kayon Jungle Resort** - views. 2. **Alaya Resort Ubud** - pool', true, ['Kayon Jungle Resort']);
        $this->answer($scan, $hotelFirst, '1. **Alaya Resort Ubud** - pool. 2. **Kayon Jungle Resort** - views', true, ['Kayon Jungle Resort']);
        $this->answer($scan, $noCompetitor, '1. **Other Hotel** - nice', false, []);

        $candidates = app(CompetitorReasonService::class)->candidates($scan);

        $this->assertSame([$rankedLower->id, $notMentioned->id], $candidates->pluck('prompt_id')->all());
        $this->assertSame(['Maya Ubud Resort', 'Kayon Jungle Resort'], $candidates->first()['competitors']);
        $this->assertSame(2, $candidates->first()['wins']);
    }

    public function test_at_most_the_configured_number_of_prompts_per_scan(): void
    {
        config(['citevanta.insights.competitor_reasons.max_per_scan' => 10]);
        $hotel = $this->hotel();
        $scan = ScanRun::factory()->for($hotel)->create();

        foreach (Prompt::factory()->for($hotel)->count(12)->create() as $prompt) {
            $this->answer($scan, $prompt, '1. **Kayon Jungle Resort** - kids club', false, ['Kayon Jungle Resort']);
        }

        $this->assertCount(10, app(CompetitorReasonService::class)->candidates($scan));
    }

    public function test_has_it_is_only_kept_when_the_reason_points_at_a_filled_in_fact(): void
    {
        $hotel = $this->hotel();
        $hotel->facts()->create(['key' => 'Kids club', 'value' => 'Yes, ages 4-12', 'position' => 1]);
        $scan = ScanRun::factory()->for($hotel)->create();
        $prompt = Prompt::factory()->for($hotel)->create();
        $this->answer($scan, $prompt, '1. **Kayon Jungle Resort** - kids club', false, ['Kayon Jungle Resort']);

        $insight = app(CompetitorReasonService::class)->apply($scan, ['prompt_id' => $prompt->id, 'wins' => 1, 'competitors' => ['Kayon Jungle Resort'], 'mentions' => 1],
            new CompetitorReasonResult([
                new CompetitorReason('Kayon', 'kids club', 'Kayon has a big kids club', 'kids club', HotelHasFeature::HasIt),
                new CompetitorReason('Kayon Jungle Resort', 'rice field view', 'rice field views', null, HotelHasFeature::HasIt),
            ], 'Kayon won on its kids club.', 100, 50, 'gpt-5-mini'));

        $this->assertSame(
            [['Kayon Jungle Resort', 'Kids club', 'has_it'], ['Kayon Jungle Resort', null, 'unknown']],
            array_map(fn ($r) => [$r['competitor'], $r['fact_key'], $r['hotel_has']], $insight->reasons),
        );
    }

    public function test_sources_are_those_cited_where_a_competitor_was_recommended_without_the_hotel(): void
    {
        $hotel = $this->hotel();
        $scan = ScanRun::factory()->for($hotel)->create();
        $prompt = Prompt::factory()->for($hotel)->create();
        $this->answer($scan, $prompt, '**Kayon Jungle Resort**', false, ['Kayon Jungle Resort'], ['lonelyplanet.com', 'kayonresort.com', 'alayahotels.com']);
        $this->answer($scan, $prompt, '**Maya Ubud Resort**', false, ['Maya Ubud Resort'], ['lonelyplanet.com']);
        $this->answer($scan, $prompt, '**Alaya Resort Ubud**, **Maya Ubud Resort**', true, ['Maya Ubud Resort'], ['tripadvisor.com']);

        $this->assertSame(
            [['domain' => 'lonelyplanet.com', 'competitors' => ['Kayon Jungle Resort', 'Maya Ubud Resort'], 'answers' => 2]],
            app(CompetitorReasonService::class)->sourcesWithoutHotel($scan, $prompt->id),
        );
    }

    public function test_a_scan_runs_one_competitor_reason_call_per_selected_prompt_and_shows_them(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        config(['citevanta.fake.competitor_rate' => 0.9]);
        [$scan] = $this->completedScans($hotel, [0.3]);

        $calls = $scan->aiCalls()->where('purpose', AiPurpose::CompetitorReasons->value)->get();
        $insights = CompetitorInsight::where('scan_run_id', $scan->id)->get();

        $this->assertGreaterThan(0, $insights->count());
        $this->assertSame($insights->count(), $calls->count());
        $this->assertSame($insights->pluck('prompt_id')->map(fn ($id) => "prompt:{$id}")->sort()->values()->all(), $calls->pluck('subject')->sort()->values()->all());
        $this->assertEqualsWithDelta((float) $calls->sum('cost_usd'), $scan->cost_breakdown['competitor_reasons'], 1e-6);

        $name = $insights->first()->competitors[0];
        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('Why competitors win')
            ->assertSee('Why '.$name);
    }

    public function test_competitor_reasons_can_be_switched_off(): void
    {
        config(['citevanta.insights.competitor_reasons.enabled' => false, 'citevanta.fake.competitor_rate' => 0.9]);
        $hotel = $this->scannableHotel(prompts: 5);
        [$scan] = $this->completedScans($hotel, [0.3]);

        $this->assertSame(0, $scan->aiCalls()->where('purpose', AiPurpose::CompetitorReasons->value)->count());
        $this->assertSame(0, CompetitorInsight::count());
    }
}
