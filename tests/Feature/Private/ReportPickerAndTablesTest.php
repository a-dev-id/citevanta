<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Jobs\GoogleSyncJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleSync;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\BookingDemandReport;
use App\Services\Reports\ReportFindings;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SearchReport;
use App\Services\Reports\TableOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reports: the date picker and comparison, and tables that are paged, sorted and exported on the server.
 * "Today" is 24 Nov 2026, so the last full day is 23 Nov.
 */
class ReportPickerAndTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: HotelGoogleLink} */
    private function privateHotel(): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandinibali.com', 'setup_completed_at' => now()]);
        $link = HotelGoogleLink::create([
            'hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'ga4_currency' => 'IDR', 'gsc_site' => 'sc-domain:nandinibali.com',
            'first_synced_at' => now()->subWeek(),
        ]);

        return [$owner, $hotel, $link];
    }

    private function traffic(Hotel $hotel, string $date, string $source, int $sessions, string $page = '/', string $country = 'Australia', int $purchases = 0): void
    {
        DB::table('ga4_traffic_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1($date.$source.$page.$country), 'source' => $source, 'medium' => 'referral',
            'country' => $country, 'landing_page' => $page, 'device' => 'mobile', 'sessions' => $sessions, 'users' => $sessions, 'engaged_sessions' => 1,
            'key_events' => 0, 'purchases' => $purchases, 'revenue' => $purchases * 1000,
        ]);
    }

    /** 25 landing pages from ChatGPT: p01 has the most sessions, p25 the fewest. */
    private function manyPages(Hotel $hotel, string $date = '2026-11-10'): void
    {
        foreach (range(1, 25) as $i) {
            $this->traffic($hotel, $date, 'chatgpt.com', 100 - $i, sprintf('/p%02d', $i), 'Australia', $i === 5 ? 3 : 0);
        }
    }

    private function search(Hotel $hotel, string $query, int $clicks, int $impressions, string $date = '2026-11-10', string $page = '/rooms', string $country = 'idn'): void
    {
        DB::table('gsc_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1($date.$query.$page.$country), 'query' => $query, 'page' => $page, 'country' => $country,
            'device' => 'MOBILE', 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $clicks / max($impressions, 1), 'position' => 5,
        ]);
    }

    private function ranges(ReportPeriod $period): array
    {
        return [$period->from->toDateString(), $period->to->toDateString(), $period->compareFrom?->toDateString(), $period->compareTo?->toDateString()];
    }

    // --- the picker: presets ---------------------------------------------------------

    public function test_every_preset_covers_the_right_days(): void
    {
        $expected = [
            '7d' => ['2026-11-17', '2026-11-23'], '28d' => ['2026-10-27', '2026-11-23'], '30d' => ['2026-10-25', '2026-11-23'], '90d' => ['2026-08-26', '2026-11-23'],
            'this_month' => ['2026-11-01', '2026-11-23'], 'last_month' => ['2026-10-01', '2026-10-31'], '12m' => ['2025-11-24', '2026-11-23'], 'this_year' => ['2026-01-01', '2026-11-23'],
        ];

        foreach ($expected as $key => [$from, $to]) {
            $period = ReportPeriod::resolve(['range' => $key]);
            $this->assertSame([$from, $to], [$period->from->toDateString(), $period->to->toDateString()], $key);
            $this->assertSame($key, $period->matchedPreset(), $key);
        }

        $this->assertSame('28d', ReportPeriod::resolve(['range' => 'nonsense'])->key, 'an unknown preset falls back to 28 days');
    }

    public function test_the_range_never_starts_before_the_first_synced_day_and_a_custom_range_never_ends_after_the_last_complete_day(): void
    {
        $earliest = Carbon::parse('2026-11-01');
        $latest = Carbon::parse('2026-11-20');

        $preset = ReportPeriod::resolve(['range' => '90d'], $earliest, $latest);
        $this->assertSame('2026-11-01', $preset->from->toDateString(), 'a preset is cut at the first synced day');
        $this->assertSame('2026-11-23', $preset->to->toDateString(), 'a preset still ends yesterday: the unsynced days are blank, with a notice');

        $custom = ReportPeriod::resolve(['from' => '2026-09-01', 'to' => '2026-12-31'], $earliest, $latest);
        $this->assertSame(['custom', '2026-11-01', '2026-11-20'], [$custom->key, $custom->from->toDateString(), $custom->to->toDateString()]);

        $swapped = ReportPeriod::resolve(['from' => '2026-11-15', 'to' => '2026-11-05'], $earliest, $latest);
        $this->assertSame(['2026-11-05', '2026-11-15'], [$swapped->from->toDateString(), $swapped->to->toDateString()], 'a backwards range is turned round');

        $junk = ReportPeriod::resolve(['from' => 'yesterday', 'to' => '2026-02-30'], $earliest, $latest);
        $this->assertSame('28d', $junk->key, 'invalid dates are ignored');
        $this->assertSame('Custom range (5 Nov 2026 to 15 Nov 2026)', $swapped->label());
    }

    // --- comparison ----------------------------------------------------------------------

    public function test_the_default_comparison_is_the_previous_period(): void
    {
        $period = ReportPeriod::resolve(['range' => '28d'], Carbon::parse('2025-01-01'));

        $this->assertSame('previous', $period->compare);
        $this->assertSame(['2026-10-27', '2026-11-23', '2026-09-29', '2026-10-26'], $this->ranges($period), 'the same 28 days, directly before');
        $this->assertSame(28, (int) $period->compareFrom->diffInDays($period->compareTo) + 1);
        $this->assertSame('vs previous period', $period->compareShortName());
    }

    public function test_same_period_last_year_with_and_without_matching_the_day_of_week(): void
    {
        $earliest = Carbon::parse('2024-01-01');

        $plain = ReportPeriod::resolve(['range' => '28d', 'compare' => 'year'], $earliest);
        $this->assertSame(['2026-10-27', '2026-11-23', '2025-10-27', '2025-11-23'], $this->ranges($plain));

        $weekdays = ReportPeriod::resolve(['range' => '28d', 'compare' => 'year', 'dow' => '1'], $earliest);
        $this->assertSame(['2025-10-28', '2025-11-24'], [$weekdays->compareFrom->toDateString(), $weekdays->compareTo->toDateString()], '52 weeks back');
        $this->assertSame($weekdays->from->dayOfWeek, $weekdays->compareFrom->dayOfWeek, 'a Tuesday against a Tuesday');
        $this->assertStringContainsString('(same weekdays)', $weekdays->compareName());

        $none = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'], $earliest);
        $this->assertSame(['none', null], [$none->compare, $none->compareFrom]);
        $this->assertFalse($none->hasComparison());
    }

    public function test_a_comparison_that_would_start_before_the_first_synced_day_is_disabled_with_the_reason(): void
    {
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'previous'], Carbon::parse('2026-10-10'));

        $this->assertSame('none', $period->compare, 'the comparison is not used');
        $this->assertNull($period->compareFrom);
        $this->assertSame(['previous' => 'data starts 10 Oct 2026', 'year' => 'data starts 10 Oct 2026'], $period->unavailable);

        $partly = ReportPeriod::resolve(['range' => '28d', 'compare' => 'previous'], Carbon::parse('2026-09-29'));
        $this->assertSame('previous', $partly->compare, 'exactly enough data');
        $this->assertSame(['year' => 'data starts 29 Sep 2026'], $partly->unavailable);

        $short = ReportPeriod::resolve(['range' => '28d', 'compare' => 'previous'], Carbon::parse('2026-09-30'));
        $this->assertSame('none', $short->compare, 'one day short is not enough');
    }

    public function test_a_disabled_comparison_is_explained_on_the_page_and_never_drawn_as_zeros(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', 30);
        $this->traffic($hotel, '2026-11-12', 'google', 70);

        $page = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();
        $page->assertSee('Previous period is not available for this range: data starts 10 Nov 2026.')
            ->assertSee('Same period last year is not available for this range: data starts 10 Nov 2026.')
            ->assertDontSee('vs previous period')->assertDontSee('Previous sessions');
        $this->assertMatchesRegularExpression('/<option value="previous"[^>]*disabled[^>]*>Previous period \(data starts 10 Nov 2026\)/', $page->getContent());

        $report = new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d'], ReportPeriod::firstGa4Day($hotel->id), Carbon::parse('2026-11-12')));
        $chart = $report->charts()['sessions'];
        $this->assertCount(14, $chart['labels'], 'the chart starts at the first synced day, not 28 days back');
        $this->assertCount(1, $chart['series'], 'no comparison line');
    }

    public function test_the_comparison_line_is_dashed_and_cards_and_tables_show_the_change(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-10-05', 'chatgpt.com', 20, '/rooms', 'Germany');
        $this->traffic($hotel, '2026-10-06', 'chatgpt.com', 5, '/spa', 'Germany');
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', 30, '/rooms', 'Germany');
        $this->traffic($hotel, '2026-11-11', 'chatgpt.com', 12, '/offers', 'France');
        $this->traffic($hotel, '2026-09-01', 'google', 1);
        $report = new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d'], ReportPeriod::firstGa4Day($hotel->id)));

        $series = $report->charts()['sessions']['series'];
        $this->assertTrue($series[1]['dash'], 'the comparison line is dashed');
        $this->assertSame('AI sessions, previous period', $series[1]['label']);
        $this->assertSame(25, array_sum($series[1]['data']));
        $this->assertSame(42, array_sum($series[0]['data']));

        $countries = $report->tables()['ai-countries'];
        $this->assertSame(['Germany', 'France'], array_column($countries->rows, 'name'));
        $this->assertEqualsWithDelta(0.2, $countries->rows[0]['change'], 0.0001, 'Germany 30 against 25');
        $this->assertSame(25, $countries->rows[0]['prev_sessions']);
        $this->assertNull($countries->rows[1]['change'], 'France had nothing before: no percentage');
        $this->assertSame(['Country', 'AI sessions', 'Previous sessions', 'Change'], array_slice($countries->csvHeader(), 0, 4));

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()
            ->assertSee('+68% vs previous period')->assertSee('Previous sessions')->assertSee('+20%')->assertSee('Compared with 29 Sep 2026 to 26 Oct 2026');
    }

    public function test_the_selection_is_kept_in_the_url_and_applied_to_both_tabs(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-10-01', 'chatgpt.com', 10);
        $this->search($hotel, 'bali spa', 5, 100, '2026-10-01');
        $this->search($hotel, 'bali pool', 3, 50, '2026-11-15'); // search data runs to 15 Nov
        $query = ['from' => '2026-10-01', 'to' => '2026-11-15', 'compare' => 'none'];

        $ai = $this->actingAs($owner)->get(route('hotels.reports', [$hotel] + $query))->assertOk();
        $ai->assertSee('Custom range (1 Oct 2026 to 15 Nov 2026)');
        $this->assertStringContainsString('report=search&from=2026-10-01&to=2026-11-15&compare=none', html_entity_decode($ai->getContent()), 'the Google Search tab link carries the same selection');

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'report' => 'search'] + $query))->assertOk()
            ->assertSee('Custom range (1 Oct 2026 to 15 Nov 2026)')->assertSee('bali spa');
    }

    // --- pagination -------------------------------------------------------------------------

    public function test_a_table_shows_10_rows_by_default_and_pages_have_the_right_boundaries(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel);
        $page = fn (int $number, array $extra = []) => $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'page' => ['ai-pages' => $number]] + $extra));
        $names = fn (array $rows) => array_column($rows, 'name');
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'], ReportPeriod::firstGa4Day($hotel->id));
        $table = fn (int $number, int $size = 10) => (new AiTrafficReport($hotel, $period))->tables(new TableOptions($size, ['ai-pages' => $number]))['ai-pages'];

        $first = $table(1);
        $this->assertSame(['/p01', '/p10'], [$names($first->rows)[0], $names($first->rows)[9]]);
        $this->assertSame([25, 1, 10, 1, 3], [$first->page->total, $first->page->page, $first->page->to, $first->page->from, $first->page->lastPage]);

        $last = $table(3);
        $this->assertCount(5, $last->rows, 'the last page holds the rest');
        $this->assertSame([21, 25, 25], [$last->page->from, $last->page->to, $last->page->total]);
        $this->assertSame('/p25', $last->rows[4]['name']);

        $beyond = $table(99);
        $this->assertSame(3, $beyond->page->page, 'a page past the end shows the last page');
        $this->assertCount(5, $beyond->rows);
        $this->assertSame(1, $table(0)->page->page, 'page 0 is page 1');

        $this->assertCount(25, $table(1, 50)->rows, '50 rows per page holds all 25');
        $this->assertSame(1, $table(1, 50)->page->lastPage);

        $page(1)->assertSee('Showing 1–10 of 25');
        $page(3)->assertSee('Showing 21–25 of 25')->assertSee('/p25')->assertDontSee('/p05');
        $page(1)->assertSee('aria-label="By landing page pages"', false);
    }

    public function test_page_controls_keep_the_range_and_a_new_size_or_sort_goes_back_to_page_1(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel);

        $html = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'size' => 10, 'page' => ['ai-pages' => 2, 'ai-countries' => 1]]))->assertOk()->getContent();
        $block = $this->between($html, 'id="pager-ai-pages"', '</nav>');
        $decoded = html_entity_decode($block);

        $this->assertStringContainsString('size=50', $decoded);
        $this->assertStringNotContainsString('page[', $this->hrefContaining($decoded, 'size=50'), 'a new page size drops every page number');
        $this->assertStringContainsString('compare=none', $this->hrefContaining($decoded, 'size=50'), 'the comparison stays');
        $this->assertStringContainsString('page[ai-pages]=3', html_entity_decode(urldecode($decoded)));

        $sortLink = $this->hrefContaining(html_entity_decode(urldecode($this->between($html, 'id="table-ai-pages"', 'id="pager-ai-pages"'))), 'data-sort="name"');
        $this->assertStringNotContainsString('page[ai-pages]', $sortLink, 'sorting goes back to page 1 of that table');
    }

    public function test_the_page_size_is_remembered_for_the_user_and_invalid_sizes_are_ignored(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel);

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'size' => 50]))->assertSee('Showing 1–25 of 25');
        $this->assertSame(50, $owner->fresh()->report_page_size);

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'from' => '2026-11-01', 'to' => '2026-11-20']))->assertSee('Showing 1–25 of 25');

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'size' => 7]))->assertSee('Showing 1–25 of 25');
        $this->assertSame(50, $owner->fresh()->report_page_size, 'a made-up size changes nothing');

        $other = User::factory()->withTeam($owner->currentTeam)->create();
        $this->actingAs($other)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertSee('Showing 1–10 of 25');
    }

    // --- sorting -----------------------------------------------------------------------------

    public function test_sorting_covers_all_rows_not_only_the_visible_page(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel);
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'], ReportPeriod::firstGa4Day($hotel->id));
        $rows = fn (string $sort, int $page = 1) => array_column((new AiTrafficReport($hotel, $period))->tables(new TableOptions(10, ['ai-pages' => $page], ['ai-pages' => $sort]))['ai-pages']->rows, 'name');

        $this->assertSame('/p01', $rows('sessions:desc')[0], 'the default: most sessions first');
        $this->assertSame('/p25', $rows('sessions:asc')[0], 'ascending starts with the smallest row of ALL rows, which is on the last page by default');
        $this->assertSame('/p01', $rows('name:asc')[0]);
        $this->assertSame('/p25', $rows('name:desc')[0]);
        $this->assertSame('/p05', $rows('purchases:desc')[0], 'the only row with purchases comes first, wherever it was');
        $this->assertSame('/p05', $rows('revenue:desc')[0]);
        $this->assertSame('/p15', $rows('sessions:asc', 2)[0], 'page 2 of the ascending order continues after the 10 smallest');

        $this->assertSame('/p01', $rows('nonsense:asc')[0], 'an unknown column is ignored');
        $this->assertSame('/p01', $rows('sessions:sideways')[0], 'an unknown direction is ignored');

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'sort' => ['ai-pages' => 'purchases:desc']]))->assertOk()->assertSee('aria-sort="descending"', false);
    }

    public function test_search_tables_are_sorted_and_paged_too(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        foreach (range(1, 25) as $i) {
            $this->search($hotel, sprintf('query %02d', $i), $i, 1000 - $i * 10, '2026-11-10', "/page-{$i}");
        }
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'], Carbon::parse('2026-10-01'));
        $rows = fn (string $sort, int $page = 1) => array_column((new SearchReport($hotel, $period))->tables(new TableOptions(10, ['search-queries' => $page], ['search-queries' => $sort]))['search-queries']->rows, 'name');

        $this->assertSame('query 25', $rows('clicks:desc')[0], 'the default: most clicks first');
        $this->assertSame('query 01', $rows('clicks:asc')[0]);
        $this->assertSame('query 01', $rows('impressions:desc')[0]);
        $this->assertSame('query 25', $rows('ctr:desc')[0]);
        $this->assertSame(['query 05', 'query 04', 'query 03', 'query 02', 'query 01'], array_slice($rows('clicks:desc', 3), 0, 5), 'page 3 of 25 rows');

        $page = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'report' => 'search', 'compare' => 'none', 'from' => '2026-10-01', 'to' => '2026-11-23']))->assertOk();
        $page->assertSee('Showing 1–10 of 25')->assertSee('query 25');
        $this->assertStringNotContainsString('query 01', $this->between($page->getContent(), 'id="table-search-queries"', 'id="table-search-pages"'), 'the lowest query is on a later page');
        foreach (['search-queries', 'search-pages', 'search-countries', 'search-seasonal', 'search-opportunities'] as $key) {
            $this->assertStringContainsString('id="table-'.$key.'"', $page->getContent());
        }
    }

    public function test_demand_by_check_in_date_is_paged_and_sorted(): void
    {
        [, $hotel] = $this->privateHotel();
        foreach (range(1, 12) as $i) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k'.$i, 'event_name' => 'search_dates',
                'checkin' => Carbon::parse('2026-12-01')->addDays($i)->toDateString(), 'checkout' => null, 'nights' => 2, 'events' => $i * 3]);
        }
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none']);
        $table = fn (array $sorts = [], int $page = 1) => (new BookingDemandReport($hotel, $period))->tables(new TableOptions(10, ['demand-checkin' => $page], $sorts))['demand-checkin'];

        $this->assertSame(['2026-12-02', '2026-12-11'], [$table()->rows[0]['date'], $table()->rows[9]['date']], 'soonest first by default');
        $this->assertCount(2, $table([], 2)->rows);
        $this->assertSame('2026-12-13', $table(['demand-checkin' => 'demand:desc'])->rows[0]['date'], 'the most searched date of all 12');
        $this->assertSame([1, 12, 12], [$table()->page->page, $table()->page->total, $table()->page->total]);
    }

    // --- CSV ----------------------------------------------------------------------------------

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", trim($content)))));
    }

    public function test_the_csv_has_every_row_of_the_range_not_only_the_page_with_the_range_in_the_file_name(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel);

        $response = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'ai-pages', 'from' => '2026-11-01', 'to' => '2026-11-20', 'compare' => 'none', 'size' => 10, 'page' => ['ai-pages' => 3]]))->assertOk();
        $rows = $this->csv($response->streamedContent());

        $this->assertCount(26, $rows, 'the header and all 25 landing pages, not 10 and not the 5 of page 3');
        $this->assertSame(['Landing page', 'AI sessions', 'Share of AI sessions', 'Purchases', 'Revenue', 'Purchases per session'], $rows[0]);
        $this->assertSame(['/p01', '99'], array_slice($rows[1], 0, 2), 'the default order: most sessions first');
        $this->assertStringContainsString('-ai-pages-20261110-20261120.csv', $response->headers->get('content-disposition'), 'the range, cut at the first synced day (10 Nov)');
    }

    public function test_the_csv_follows_the_chosen_sort_and_has_the_comparison_columns_when_comparison_is_on(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->manyPages($hotel, '2026-11-10');
        $this->traffic($hotel, '2026-10-01', 'google', 1); // the data starts early enough for a comparison
        foreach (range(1, 12) as $i) {
            $this->traffic($hotel, '2026-11-02', 'chatgpt.com', 10, sprintf('/p%02d', $i)); // the period before
        }

        $response = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'ai-pages', 'from' => '2026-11-10', 'to' => '2026-11-23', 'compare' => 'previous', 'sort' => ['ai-pages' => 'name:asc']]))->assertOk();
        $rows = $this->csv($response->streamedContent());

        $this->assertSame(['Landing page', 'AI sessions', 'Previous sessions', 'Change', 'Share of AI sessions', 'Purchases', 'Revenue', 'Purchases per session'], $rows[0]);
        $this->assertCount(26, $rows);
        $this->assertSame(['/p01', '99', '10'], array_slice($rows[1], 0, 3), 'sorted by name, with last period\'s sessions');
        $this->assertSame('8.9', rtrim((string) $rows[1][3], '0'), 'the change is a fraction: 99 against 10 is +890%');
        $this->assertSame('', $rows[13][3] ?? '', 'a page with no previous sessions has no change');
        $this->assertStringContainsString('-vs-', $response->headers->get('content-disposition'));
    }

    public function test_search_and_demand_csv_files_also_have_every_row(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        foreach (range(1, 30) as $i) {
            $this->search($hotel, sprintf('q%02d', $i), $i, 500, '2026-11-10', '/p'.$i);
        }
        foreach (range(1, 14) as $i) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k'.$i, 'event_name' => 'search_dates', 'checkin' => Carbon::parse('2026-12-01')->addDays($i)->toDateString(), 'nights' => 2, 'events' => $i]);
        }
        $query = ['from' => '2026-11-01', 'to' => '2026-11-20', 'compare' => 'none'];

        $this->assertCount(31, $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'search-queries'] + $query))->streamedContent()));
        $this->assertCount(31, $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'search-pages'] + $query))->streamedContent()));
        $this->assertCount(15, $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-checkin'] + $query))->streamedContent()));
        $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'nope-table'] + $query))->assertNotFound();
    }

    // --- findings ------------------------------------------------------------------------------

    public function test_findings_never_name_not_set_as_a_page(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', 50, '(not set)');
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none']);
        $findings = app(ReportFindings::class)->forAiTraffic($hotel, new AiTrafficReport($hotel, $period));

        $this->assertStringNotContainsString('(not set)', json_encode($findings));
        $this->assertSame([], array_filter($findings, fn ($finding) => $finding['level'] === 'warning'), 'unrecorded pages are skipped');

        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', 20, '/rooms');
        $findings = app(ReportFindings::class)->forAiTraffic($hotel, new AiTrafficReport($hotel, $period));
        $this->assertStringContainsString('/rooms had 20 visits', $findings[0]['text']);
        $this->assertStringNotContainsString('(not set)', json_encode($findings));
    }

    // --- sync depth ----------------------------------------------------------------------------

    public function test_the_ga4_history_is_25_months_and_the_search_console_history_stays_at_16(): void
    {
        $this->assertSame(25, config('citevanta.google.ga4_first_sync_months'));
        $this->assertSame(16, config('citevanta.google.gsc_first_sync_months'));

        [, , $link] = $this->privateHotel();
        $link->forceFill(['first_synced_at' => null])->save();
        Bus::fake();

        app(GoogleSync::class)->schedule($link);

        $from = now()->subDay()->subMonthsNoOverflow(25)->toDateString();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->from === $from);
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GSC_DAY && $job->from === now()->subDay()->subMonthsNoOverflow(16)->toDateString());
    }

    public function test_the_backfill_reads_only_the_older_windows(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        foreach (['ga4_traffic_daily' => ['row_key' => sha1('a'), 'source' => 'google'], 'ga4_events_daily' => ['event_name' => 'page_view']] as $table => $extra) {
            DB::table($table)->insert(['hotel_id' => $hotel->id, 'date' => '2025-10-20'] + $extra);
        }
        Bus::fake();
        $wanted = now()->subDay()->subMonthsNoOverflow(25)->toDateString();

        $result = app(GoogleSync::class)->backfillTeam($hotel->team_id);

        $this->assertSame(['hotels' => 1, 'jobs' => 3], $result, 'traffic, events and the daily totals (history without totals gets them for every month)');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->from === $wanted
            && $job->to === GoogleSync::windowEnd($wanted, '2025-10-19') && $job->until === '2025-10-19', 'from 25 months back up to the day before the oldest stored day');
        Bus::assertNotDispatched(GoogleSyncJob::class, fn ($job) => $job->to > '2025-10-19' && $job->from >= '2025-10-20');
    }

    public function test_nothing_is_backfilled_when_the_data_already_reaches_back_or_nothing_is_stored(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        Bus::fake();
        $sync = app(GoogleSync::class);

        $this->assertSame(0, $sync->backfill($link), 'nothing stored: the normal first sync does that');

        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => now()->subMonths(26)->toDateString(), 'row_key' => sha1('old'), 'source' => 'google']);
        $this->assertSame(1, $sync->backfill($link), 'the traffic is already 26 months deep, but it has no daily totals yet: only they are copied');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TOTALS && $job->until === now()->subDay()->toDateString());
        Bus::assertNotDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC);

        DB::table('ga4_totals_daily')->insert(['hotel_id' => $hotel->id, 'date' => now()->subMonths(26)->toDateString()]);
        Bus::fake();
        $this->assertSame(0, $sync->backfill($link), 'everything reaches back far enough');
        Bus::assertNothingDispatched();
    }

    public function test_the_backfill_command_and_the_admin_button_start_it(): void
    {
        [, $hotel] = $this->privateHotel();
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-06-01', 'row_key' => sha1('a'), 'source' => 'google']);
        Bus::fake();

        $this->artisan('citevanta:google-backfill', ['team' => (string) $hotel->team_id])->assertSuccessful();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->until === '2026-05-31');
        $this->artisan('citevanta:google-backfill', ['team' => 'No such team'])->assertFailed();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('Backfill older data');
        $this->actingAs($admin)->post(route('admin.integrations.google.backfill'), ['hotel' => $hotel->id])->assertRedirect(route('admin.integrations.google'))->assertSessionHas('status');
        $this->actingAs(User::factory()->withTeam($hotel->team)->create())->post(route('admin.integrations.google.backfill'), ['hotel' => $hotel->id])->assertForbidden();
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function between(string $text, string $start, string $end): string
    {
        $a = strpos($text, $start);
        $b = strpos($text, $end, $a === false ? 0 : $a);

        return $a === false || $b === false ? '' : substr($text, $a, $b - $a);
    }

    /** The href that contains a text (links in a block of HTML). */
    private function hrefContaining(string $html, string $needle): string
    {
        preg_match_all('/<a [^>]*>/', $html, $matches);

        foreach ($matches[0] as $tag) {
            if (str_contains($tag, $needle)) {
                return $tag;
            }
        }

        return '';
    }
}
