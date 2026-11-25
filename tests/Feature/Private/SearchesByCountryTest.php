<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Jobs\GoogleSyncJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleSync;
use App\Services\Reports\BookingDemandReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\TableOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Searches by date" with the guest's country: the sync (GA4 country dimension), the migration, the calendar
 * tooltip data, the Country filter and the CSV. Today is 24 Nov 2026.
 */
class SearchesByCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: HotelGoogleLink} */
    private function privateHotel(array $mapping = ['checkin' => 'check_in_date', 'checkout' => 'check_out_date']): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandinibali.com', 'setup_completed_at' => now()]);
        $link = HotelGoogleLink::create([
            'hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'ga4_currency' => 'IDR', 'first_synced_at' => now()->subWeek(),
            'date_mapping' => $mapping + ['confirmed' => true],
        ]);
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'x', 'source' => 'google', 'sessions' => 10]);

        return [$owner, $hotel, $link];
    }

    private function booking(Hotel $hotel, string $event, string $checkin, ?int $nights, string $country, int $events, string $date = '2026-11-10'): void
    {
        DB::table('ga4_booking_dates_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1(json_encode([$event, $checkin, $nights, $country, $events, $date, $hotel->id])), 'event_name' => $event, 'checkin' => $checkin,
            'checkout' => $nights ? Carbon::parse($checkin)->addDays($nights)->toDateString() : null, 'nights' => $nights, 'country' => $country, 'events' => $events,
        ]);
    }

    private function report(Hotel $hotel, ?string $country = null, array $period = ['range' => '28d', 'compare' => 'none']): BookingDemandReport
    {
        return new BookingDemandReport($hotel, ReportPeriod::resolve($period, Carbon::parse('2026-10-01')), $country);
    }

    /** A GA4 row for the booking dates request: date, event, check-in, check-out, country. */
    private function ga4Row(string $date, string $event, string $checkin, string $checkout, string $country, int $count): array
    {
        return ['dimensionValues' => array_map(fn ($v) => ['value' => $v], [str_replace('-', '', $date), $event, $checkin, $checkout, $country]), 'metricValues' => [['value' => (string) $count]]];
    }

    // --- the migration -------------------------------------------------------------------------------

    public function test_the_migration_only_adds_an_empty_country_column_and_keeps_every_old_row(): void
    {
        [, $hotel] = $this->privateHotel();
        $migration = require base_path('database/migrations/2026_11_25_044814_add_country_to_ga4_booking_dates_daily.php');

        $this->assertTrue(Schema::hasColumn('ga4_booking_dates_daily', 'country'));

        // The table as it is on the live server before the migration: no country column, with real rows in it.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('ga4_booking_dates_daily', 'country'));
        foreach ([['view_item', 3, 40], ['begin_checkout', 3, 5], ['search_dates', 2, 7]] as $i => [$event, $nights, $events]) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1("old{$i}"), 'event_name' => $event, 'checkin' => '2026-12-24', 'checkout' => '2026-12-27', 'nights' => $nights, 'events' => $events]);
        }
        $before = DB::table('ga4_booking_dates_daily')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $migration->up();

        $this->assertTrue(Schema::hasColumn('ga4_booking_dates_daily', 'country'));
        $after = DB::table('ga4_booking_dates_daily')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertCount(3, $after, 'no row was deleted');
        foreach ($after as $i => $row) {
            $this->assertSame('', $row['country'], 'old rows get an empty country');
            $this->assertSame($before[$i], array_diff_key($row, ['country' => 1]), 'nothing else in the row changed (the key, the dates, the events)');
        }

        $migration->up(); // running it again does nothing
        $this->assertCount(3, DB::table('ga4_booking_dates_daily')->get());

        // The empty country shows as "Unknown" and the old rows still count.
        $this->assertSame([['value' => 'unknown', 'label' => 'Unknown', 'events' => 52]], $this->report($hotel)->countryOptions());
    }

    public function test_the_country_column_is_a_short_text_that_defaults_to_empty(): void
    {
        [, $hotel] = $this->privateHotel();
        DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k', 'event_name' => 'search_dates', 'checkin' => '2026-12-24', 'events' => 1]);

        $this->assertSame('', DB::table('ga4_booking_dates_daily')->value('country'));
        $this->assertSame(['hotel_id', 'row_key'], collect(Schema::getIndexes('ga4_booking_dates_daily'))->where('primary', false)->firstWhere('unique', true)['columns'], 'the unique key is still hotel + row_key, and the row key includes the country');
    }

    // --- the sync ---------------------------------------------------------------------------------------

    /** @param list<string> $expected */
    private function assertBookingDimensions(array $mapping, array $expected): void
    {
        [, , $link] = $this->privateHotel($mapping);
        $captured = null;
        $this->mock(GoogleApi::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('runReport')->once()->andReturnUsing(function ($property, array $body) use (&$captured): array {
                $captured = $body;

                return ['rows' => [], 'rowCount' => 0];
            });
        });

        app(GoogleSync::class)->run($link, GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-10', 0, 'b');

        $dimensions = array_column($captured['dimensions'], 'name');
        $this->assertSame($expected, $dimensions);
        $this->assertLessThanOrEqual(9, count($dimensions), 'GA4 allows 9 dimensions per request');
        $this->assertSame($dimensions, array_map(fn ($order) => $order['dimension']['dimensionName'], $captured['orderBys']), 'ordered by every dimension');
    }

    public function test_the_booking_dates_request_asks_for_the_country_with_check_in_and_check_out(): void
    {
        $this->assertBookingDimensions(['checkin' => 'ci', 'checkout' => 'co'], ['date', 'eventName', 'customEvent:ci', 'customEvent:co', 'country']);
    }

    public function test_the_booking_dates_request_asks_for_the_country_with_check_in_and_nights(): void
    {
        $this->assertBookingDimensions(['checkin' => 'ci', 'nights' => 'n'], ['date', 'eventName', 'customEvent:ci', 'customEvent:n', 'country']);
    }

    public function test_the_booking_dates_request_asks_for_the_country_with_check_in_only(): void
    {
        $this->assertBookingDimensions(['checkin' => 'ci'], ['date', 'eventName', 'customEvent:ci', 'country']);
    }

    public function test_the_sync_stores_one_row_per_country_and_the_total_of_every_day_is_unchanged(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        // What GA4 returns now (with the country), and what the old request would have returned in total per day.
        $rows = [
            $this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', 'Australia', 2),
            $this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', 'Germany', 1),
            $this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', 'Côte d\'Ivoire', 4),
            $this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', '(not set)', 3),
            $this->ga4Row('2026-11-10', 'begin_checkout', '2026-12-24', '2026-12-27', 'Australia', 1),
            $this->ga4Row('2026-11-11', 'search_dates', '2026-12-31', '2027-01-02', 'Japan', 5),
            $this->ga4Row('2026-11-11', 'search_dates', '2026-12-31', '2027-01-02', 'Australia', 6),
        ];
        $oldTotals = ['2026-11-10' => 2 + 1 + 4 + 3 + 1, '2026-11-11' => 5 + 6];
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => $rows, 'rowCount' => count($rows)]));

        app(GoogleSync::class)->run($link, GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-11', 0, 'b1');

        $this->assertSame(7, DB::table('ga4_booking_dates_daily')->count(), 'one row per date, event, stay and country');
        $totals = DB::table('ga4_booking_dates_daily')->groupBy('date')->selectRaw('date, sum(events) as events')->pluck('events', 'date')->map(fn ($n) => (int) $n)->all();
        $this->assertSame($oldTotals, $totals, 'the sum over the countries is the old total, for every day');
        $this->assertEqualsCanonicalizing(['Australia', 'Germany', 'Côte d\'Ivoire', '(not set)', 'Japan'], DB::table('ga4_booking_dates_daily')->distinct()->pluck('country')->all(), 'the country as GA4 returns it');

        foreach (['city', 'region', 'ip', 'user_agent'] as $personal) {
            $this->assertFalse(Schema::hasColumn('ga4_booking_dates_daily', $personal), "no {$personal} column");
        }
        $this->assertEqualsCanonicalizing(['id', 'hotel_id', 'date', 'row_key', 'event_name', 'checkin', 'checkout', 'nights', 'country', 'events', 'sync_batch'], Schema::getColumnListing('ga4_booking_dates_daily'), 'only the country was added');
    }

    public function test_reading_the_same_rows_again_never_doubles_them_and_old_rows_are_replaced_by_date(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        // An old row (before countries): one total for the date, with an empty country.
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, '', 10);
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, '', 99, '2026-11-20'); // another day, outside the window
        $rows = [$this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', 'Australia', 6), $this->ga4Row('2026-11-10', 'search_dates', '2026-12-24', '2026-12-27', 'Germany', 4)];
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => $rows, 'rowCount' => 2]));
        $sync = app(GoogleSync::class);

        $sync->run($link, GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-12', 0, 'one');
        $sync->run($link, GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-12', 0, 'one');
        $sync->run($link, GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-12', 0, 'two');

        $this->assertSame(10, (int) DB::table('ga4_booking_dates_daily')->where('date', '2026-11-10')->sum('events'), 'the old total of that date is replaced by the countries, not added to them');
        $this->assertSame(2, DB::table('ga4_booking_dates_daily')->where('date', '2026-11-10')->count());
        $this->assertSame(99, (int) DB::table('ga4_booking_dates_daily')->where('date', '2026-11-20')->sum('events'), 'a day outside the window keeps its old row until it is re-read');
    }

    /** A GA4 that returns two countries for every day of a booking dates request and nothing else. */
    private function bookingDatesApi(array &$requests): void
    {
        $this->mock(GoogleApi::class, function (MockInterface $mock) use (&$requests): void {
            $mock->shouldReceive('runReport')->andReturnUsing(function ($property, array $body) use (&$requests): array {
                $names = array_column($body['dimensions'] ?? [], 'name');

                if (! in_array('customEvent:check_in_date', $names, true)) {
                    return ['rows' => [], 'rowCount' => 0];
                }

                $requests[] = $body;
                $rows = [];
                for ($day = Carbon::parse($body['dateRanges'][0]['startDate']); $day->lte(Carbon::parse($body['dateRanges'][0]['endDate'])); $day->addDay()) {
                    $rows[] = $this->ga4Row($day->toDateString(), 'search_dates', '2026-12-24', '2026-12-27', 'Australia', 2);
                    $rows[] = $this->ga4Row($day->toDateString(), 'search_dates', '2026-12-24', '2026-12-27', 'Germany', 1);
                }

                return ['rows' => $rows, 'rowCount' => count($rows)];
            });
        });
    }

    private function runQueuedSyncJobs(): void
    {
        $ran = 0;

        while (($jobs = Bus::dispatched(GoogleSyncJob::class)->values())->count() > $ran) {
            $jobs[$ran]->handle(app(GoogleSync::class));
            $ran++;
        }
    }

    public function test_re_sync_from_scratch_sync_now_and_the_daily_sync_all_fill_the_country(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, '', 50, '2026-11-12'); // an old row, before countries
        $requests = [];
        $this->bookingDatesApi($requests);

        // Re-sync from scratch: the whole history, window by window.
        Bus::fake();
        app(GoogleSync::class)->resyncTeam($hotel->team_id);
        $this->runQueuedSyncJobs();
        $this->assertNotEmpty($requests);
        foreach ($requests as $body) {
            $this->assertContains('country', array_column($body['dimensions'], 'name'));
        }
        $first = now()->subDay()->subMonthsNoOverflow(25)->toDateString();
        $this->assertSame(['Australia', 'Germany'], DB::table('ga4_booking_dates_daily')->distinct()->orderBy('country')->pluck('country')->all(), 'every row has a country, the old empty-country row is gone');
        $this->assertSame($first, DB::table('ga4_booking_dates_daily')->min('date'));
        $this->assertSame(0, DB::table('ga4_booking_dates_daily')->where('country', '')->count());

        // Sync now (the last 7 days).
        DB::table('ga4_booking_dates_daily')->delete();
        $requests = [];
        Bus::fake();
        Cache::flush();
        app(GoogleSync::class)->syncRecent($link->fresh());
        $this->runQueuedSyncJobs();
        $this->assertSame(['2026-11-17', '2026-11-23'], [DB::table('ga4_booking_dates_daily')->min('date'), DB::table('ga4_booking_dates_daily')->max('date')]);
        $this->assertSame(0, DB::table('ga4_booking_dates_daily')->where('country', '')->count());
        $this->assertContains('country', array_column($requests[0]['dimensions'], 'name'));
    }

    public function test_the_daily_sync_fills_the_country_of_the_last_days(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        $link->forceFill(['booking_dates_synced_at' => now()->subDay()])->save();
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, '', 50, '2026-11-22');
        $requests = [];
        $this->bookingDatesApi($requests);
        Bus::fake();

        app(GoogleSync::class)->schedule($link->fresh(), false);
        $this->runQueuedSyncJobs();

        $this->assertContains('country', array_column($requests[0]['dimensions'], 'name'));
        $this->assertSame(['2026-11-21', '2026-11-23'], [$requests[0]['dateRanges'][0]['startDate'], $requests[0]['dateRanges'][0]['endDate']], 'the last 3 days');
        $this->assertSame(0, DB::table('ga4_booking_dates_daily')->where('date', '>=', '2026-11-21')->where('country', '')->count(), 'the old empty-country row of 22 Nov was replaced');
    }

    public function test_the_fake_ga4_returns_several_countries_for_booking_dates(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        config(['services.google.fake' => true]);
        $link->update(['ga4_property' => 'properties/'.(900000 + $hotel->id)]);

        app(GoogleSync::class)->run($link->fresh(), GoogleSync::GA4_BOOKING_DATES, '2026-11-10', '2026-11-12', 0, 'b');

        $this->assertGreaterThanOrEqual(3, DB::table('ga4_booking_dates_daily')->distinct()->count('country'));
        $this->assertSame(0, DB::table('ga4_booking_dates_daily')->where('country', '')->count());
    }

    // --- the tooltip data --------------------------------------------------------------------------------

    public function test_the_calendar_gives_each_date_its_top_three_countries_and_how_many_more(): void
    {
        [, $hotel] = $this->privateHotel();
        $d = '2026-12-24';
        $this->booking($hotel, 'search_dates', $d, 3, 'Australia', 2);
        foreach (['Germany', 'Japan', 'France', 'Italy', ''] as $country) {
            $this->booking($hotel, 'search_dates', $d, 3, $country, 1);
        }
        $this->booking($hotel, 'begin_checkout', $d, 3, 'Australia', 1);
        $this->booking($hotel, 'purchase', $d, 3, 'Spain', 9); // booked, not demand: not listed
        $this->booking($hotel, 'search_dates', '2026-12-26', 2, 'Brazil', 4);

        $days = $this->report($hotel)->calendar()['days'];

        $this->assertSame([['Australia', 2], ['France', 1], ['Germany', 1]], $days[$d]['c'], 'the 3 with most searches; ties by name');
        $this->assertSame(3, $days[$d]['m'], '+3 more: Italy, Japan and Unknown');
        $this->assertSame($days[$d]['l'], 2 + 1 + 1 + 1 + 1 + 1, 'the searched number is the sum over every country: 3 shown and 3 more');
        $this->assertSame([['Brazil', 4]], $days['2026-12-26']['c']);
        $this->assertSame($days['2026-12-26']['l'], array_sum(array_column($days['2026-12-26']['c'], 1)), 'when nothing is hidden the lines add up to the searched number');
        $this->assertSame(0, $days['2026-12-26']['m']);
        $this->assertSame([7, 1, 9, 8], [$days[$d]['l'], $days[$d]['s'], $days[$d]['b'], $days[$d]['d']], 'the three counts and the demand are the totals over all countries');
        $this->assertSame(['l', 's', 'b', 'd', 'stays', 'c', 'm'], array_keys($days[$d]), 'short keys, the old ones first');
        $this->assertSame([['Unknown', 1]], $this->report($hotel, 'unknown')->calendar()['days'][$d]['c']);
    }

    public function test_the_page_draws_the_top_countries_in_the_tooltip(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Australia', 2);
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Germany', 1);

        $page = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertOk();

        $page->assertSeeHtml('id="demand-tip-countries"')->assertSee('Top countries')->assertSeeHtml("c[0] + ' ' + c[1]")->assertSeeHtml("'+' + data[tip.key].m + ' more'");
        $html = str_replace(['&quot;', '\\u0022', '\\/'], ['"', '"', '/'], $page->getContent());
        $this->assertStringContainsString('"c":[["Australia",2],["Germany",1]],"m":0', $html);
        $this->assertStringContainsString('<table class="cv-cal__tip-table">', $page->getContent(), 'the Stay table is unchanged');
    }

    // --- the filter -----------------------------------------------------------------------------------------

    private function mixedBookings(Hotel $hotel): void
    {
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Australia', 5);
        $this->booking($hotel, 'begin_checkout', '2026-12-24', 3, 'Australia', 2);
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Germany', 3);
        $this->booking($hotel, 'search_dates', '2026-12-30', 7, 'Germany', 4);
        $this->booking($hotel, 'search_dates', '2026-12-24', 2, '', 6);
        $this->booking($hotel, 'search_dates', '2026-12-28', 2, '(not set)', 1);
    }

    public function test_the_country_filter_applies_to_the_calendar_the_stay_tables_and_the_dates(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);

        $all = $this->report($hotel)->calendar()['days'];
        $this->assertSame(['2026-12-24', '2026-12-28', '2026-12-30'], array_keys($all));
        $this->assertSame(16, $all['2026-12-24']['d'], 'no filter: every country');

        $germany = $this->report($hotel, 'Germany');
        $calendar = $germany->calendar()['days'];
        $this->assertSame(['2026-12-24', '2026-12-30'], array_keys($calendar), 'only the dates Germany searched');
        $this->assertSame([3, 4], [$calendar['2026-12-24']['d'], $calendar['2026-12-30']['d']]);
        $this->assertSame([['Germany', 3]], $calendar['2026-12-24']['c']);
        $this->assertSame([[3, 3, 0, 0]], $calendar['2026-12-24']['stays'], 'the Stay table inside the tooltip is only about Germany');

        $stays = array_column($germany->byStay(), 'looked', 'nights');
        $this->assertSame([3 => 3, 7 => 4], $stays, 'the stay-length table');
        $this->assertSame(['2026-12-24', '2026-12-30'], array_column($germany->byCheckin(), 'date'));
        $this->assertSame(7, $germany->stayHistogram()['total'], 'the stay-length chart');

        $australia = $this->report($hotel, 'Australia');
        $this->assertSame([5, 2, 0, 7], [$australia->byCheckin()[0]['looked'], $australia->byCheckin()[0]['started'], $australia->byCheckin()[0]['booked'], $australia->byCheckin()[0]['demand']]);
        $this->assertSame([], $this->report($hotel, 'Spain')->calendar()['days'], 'a country with no searches');
    }

    public function test_unknown_means_the_empty_country_and_ga4s_not_set(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);

        $unknown = $this->report($hotel, BookingDemandReport::UNKNOWN_FILTER);

        $this->assertSame(['2026-12-24', '2026-12-28'], array_keys($unknown->calendar()['days']));
        $this->assertSame([6, 1], [$unknown->calendar()['days']['2026-12-24']['d'], $unknown->calendar()['days']['2026-12-28']['d']]);
        $this->assertSame([['Unknown', 6]], $unknown->calendar()['days']['2026-12-24']['c']);
    }

    public function test_the_dropdown_lists_the_countries_of_the_period_most_searches_first(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);
        $this->booking($hotel, 'purchase', '2026-12-24', 3, 'Spain', 50); // bookings are not searches
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'France', 99, '2026-08-01'); // outside the period

        $options = $this->report($hotel)->countryOptions();

        $this->assertSame([['Australia', 7], ['Germany', 7], ['Unknown', 7]], array_map(fn ($o) => [$o['label'], $o['events']], $options));
        $this->assertSame(['Australia', 'Germany', 'unknown'], array_column($options, 'value'));
        $this->assertSame($options, $this->report($hotel, 'Germany')->countryOptions(), 'the dropdown does not shrink to the chosen country');
    }

    public function test_the_report_page_has_the_country_dropdown_and_the_filter_works_from_the_url(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);

        $all = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertOk();
        $all->assertSeeHtml('id="country-form"')->assertSeeHtml('<option value="">All countries</option>')->assertSeeHtml('<option value="Australia"')->assertSeeHtml('<option value="Germany"')->assertSeeHtml('<option value="unknown"')->assertSee('Unknown');
        $this->assertStringNotContainsString('<option value="Australia" selected', $all->getContent());

        $germany = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none', 'country' => 'Germany']))->assertOk();
        $germany->assertSeeHtml('<option value="Germany" selected');
        $html = str_replace(['&quot;', '\\u0022', '\\/'], ['"', '"', '/'], $germany->getContent());
        $this->assertStringContainsString('"2026-12-30":{', $html);
        $this->assertStringNotContainsString('"2026-12-28":{', $html, 'a date only Unknown searched is not in the Germany calendar');
        $this->assertStringContainsString('country=Germany', html_entity_decode($germany->getContent()), 'the range buttons and the CSV keep the country');
        $this->assertStringContainsString('/export/demand-detail', html_entity_decode($germany->getContent()), 'the CSV of the dates table is the detailed one');
        $germany->assertSeeHtml('name="from"')->assertSeeHtml('name="compare"');
    }

    // --- the CSV --------------------------------------------------------------------------------------------------

    /** @return list<list<string>> */
    private function csv(string $content): array
    {
        return array_map('str_getcsv', array_values(array_filter(explode("\n", trim($content)))));
    }

    public function test_the_csv_has_one_row_per_date_stay_length_and_country(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);
        $this->booking($hotel, 'purchase', '2026-12-24', 3, 'Australia', 1);
        $query = ['from' => '2026-11-01', 'to' => '2026-11-23', 'compare' => 'none'];

        $response = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $query))->assertOk();
        $rows = $this->csv($response->streamedContent());

        $this->assertSame(['Check-in date', 'Stay length (nights)', 'Country', 'Searched', 'Started a booking', 'Booked', 'Searched + started'], $rows[0]);
        $this->assertSame([
            ['2026-12-24', '2', 'Unknown', '6', '0', '0', '6'],
            ['2026-12-24', '3', 'Australia', '5', '2', '1', '7'],
            ['2026-12-24', '3', 'Germany', '3', '0', '0', '3'],
            ['2026-12-28', '2', 'Unknown', '1', '0', '0', '1'],
            ['2026-12-30', '7', 'Germany', '4', '0', '0', '4'],
        ], array_slice($rows, 1), 'one row per date, stay length and country, in that order');
        $this->assertStringContainsString('demand-detail-20261110-20261123.csv', $response->headers->get('content-disposition'), 'the range is cut at the first synced day');
    }

    public function test_the_csv_follows_the_country_filter(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);
        $query = ['from' => '2026-11-01', 'to' => '2026-11-23', 'compare' => 'none'];

        $germany = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $query + ['country' => 'Germany']))->assertOk();
        $rows = $this->csv($germany->streamedContent());

        $this->assertSame([['2026-12-24', '3', 'Germany', '3', '0', '0', '3'], ['2026-12-30', '7', 'Germany', '4', '0', '0', '4']], array_slice($rows, 1));
        $this->assertStringContainsString('-germany.csv', $germany->headers->get('content-disposition'));

        $unknown = $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $query + ['country' => 'unknown']))->streamedContent());
        $this->assertSame(['Unknown', 'Unknown'], array_column(array_slice($unknown, 1), 2));
        $this->assertCount(1, $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $query + ['country' => 'Spain']))->streamedContent()), 'a country with no searches: only the header');
    }

    public function test_the_csv_respects_the_date_picker_and_the_comparison(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Australia', 10, '2026-11-12');
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Australia', 4, '2026-11-02'); // the period before
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Germany', 2, '2026-11-12');
        $this->booking($hotel, 'search_dates', '2026-12-24', 3, 'Japan', 8, '2026-10-20');     // before both periods
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-10-01', 'row_key' => 'early', 'source' => 'google', 'sessions' => 1]); // the data starts early enough to compare

        $period = ['from' => '2026-11-10', 'to' => '2026-11-23'];
        $none = $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $period + ['compare' => 'none']))->streamedContent());
        $this->assertSame(['Australia', 'Germany'], array_column(array_slice($none, 1), 2), 'only the chosen dates');
        $this->assertSame(['Check-in date', 'Stay length (nights)', 'Country', 'Searched', 'Started a booking', 'Booked', 'Searched + started'], $none[0]);

        $previous = $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-detail'] + $period + ['compare' => 'previous']))->streamedContent());
        $this->assertSame(['Previous searched + started', 'Change'], array_slice($previous[0], -2));
        $this->assertSame(['Australia', '10', '4', '1.5'], [$previous[1][2], $previous[1][3], $previous[1][7], $previous[1][8]], 'the same date, stay and country in the previous period: +150%');
        $this->assertSame(['Germany', '2', '0', ''], [$previous[2][2], $previous[2][3], $previous[2][7], $previous[2][8]], 'nothing before: no percentage');
    }

    public function test_the_dates_table_csv_button_downloads_the_detailed_file_and_old_exports_still_work(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->mixedBookings($hotel);
        $query = ['from' => '2026-11-01', 'to' => '2026-11-23', 'compare' => 'none'];

        $tables = $this->report($hotel)->tables(new TableOptions);
        $this->assertSame('demand-detail', $tables['demand-checkin']->exportKey);
        $this->assertArrayNotHasKey('demand-detail', $tables, 'the page does not build the big table');
        $this->assertArrayHasKey('demand-detail', $this->report($hotel)->tables(TableOptions::all()));

        $oldKey = $this->csv($this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-checkin'] + $query))->streamedContent());
        $this->assertSame('Check-in date', $oldKey[0][0], 'the per-date file is still available');
        $this->assertCount(4, $oldKey, 'one row per date (24, 28 and 30 Dec) and the header');
        $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'demand-stay'] + $query + ['country' => 'Germany']))->assertOk();
    }
}
