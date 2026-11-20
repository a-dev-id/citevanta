<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class TrendTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    public function test_one_scan_shows_the_empty_state_instead_of_charts(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $this->completedScans($hotel, [0.5]);

        $this->assertNull(app(DashboardService::class)->build($hotel, $user)->trends);

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('Trends appear after your second scan.')
            ->assertDontSee('data-cv-chart=', false);
    }

    public function test_visibility_trend_has_one_point_per_scan_from_the_stored_scores(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $scans = $this->completedScans($hotel, [0.2, 0.5, 0.8]);

        $trends = app(DashboardService::class)->build($hotel, $user)->trends;
        $overall = collect($trends['visibility']['series'])->firstWhere('key', 'overall');

        $this->assertSame(3, $trends['scans']);
        $this->assertCount(3, $trends['visibility']['labels']);
        $this->assertSame(
            array_map(fn ($scan) => (float) $scan->visibilityScores()->whereNull('persona')->sole()->visibility_pct, $scans),
            $overall['data'],
        );

        // Persona lines are a second group, each read from that persona's scores.
        $family = collect($trends['visibility']['series'])->firstWhere('key', 'family');
        $this->assertSame('personas', $family['group']);
        $row = $scans[2]->visibilityScores()->where('persona', 'family')->sole();
        $this->assertSame(VisibilityReportBuilder::percentage($row->mentions, $row->answers), $family['data'][2]);
    }

    public function test_viewing_a_past_scan_ends_the_trend_at_that_scan(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        [, $middle] = $this->completedScans($hotel, [0.2, 0.5, 0.8]);

        $this->assertSame(2, app(DashboardService::class)->build($hotel, $user, $middle)->trends['scans']);
    }

    public function test_share_of_voice_trend_highlights_the_hotel_and_follows_each_competitor(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $scans = $this->completedScans($hotel, [0.3, 0.7]);

        $series = collect(app(DashboardService::class)->build($hotel, $user)->trends['share_of_voice']['series']);
        $self = $series->firstWhere('key', 'hotel');

        $this->assertTrue($self['highlight']);
        $this->assertSame('series-1', $self['color']);
        $this->assertSame((float) $scans[1]->competitorScores()->whereNull('competitor_id')->sole()->share_of_voice_pct, $self['data'][1]);
        $this->assertContains('Kayon Jungle Resort', $series->pluck('label'));
    }

    public function test_sources_trend_counts_citations_per_domain_type_per_scan(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $scans = $this->completedScans($hotel, [0.3, 0.7]);

        $chart = app(DashboardService::class)->build($hotel, $user)->trends['sources'];
        $perScan = array_map(fn ($i) => array_sum(array_column(array_column($chart['series'], 'data'), $i)), [0, 1]);

        $this->assertSame(
            array_map(fn ($scan) => $scan->responses()->withCount('citations')->get()->sum('citations_count'), $scans),
            $perScan,
        );
        $this->assertTrue(collect($chart['series'])->every(fn ($s) => str_starts_with($s['color'], 'domain-')));
    }

    public function test_the_dashboard_renders_the_three_charts_with_a_table_view(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $user = User::factory()->withTeam($hotel->team)->create();
        $this->completedScans($hotel, [0.3, 0.7]);

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('id="cv-chart-visibility"', false)
            ->assertSee('id="cv-chart-sov"', false)
            ->assertSee('id="cv-chart-sources"', false)
            ->assertSee('By persona')
            ->assertSee('<caption>Share of voice per scan for your hotel and each competitor</caption>', false);
    }
}
