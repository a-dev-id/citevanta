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
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SearchReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Private plan reports: AI traffic and bookings (GA4), demand by date,
 * Google Search. Everything is read from our own synced tables.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: HotelGoogleLink} */
    private function privateHotel(bool $mapped = true): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandinibali.com', 'city' => 'Ubud', 'setup_completed_at' => now()]);
        $link = HotelGoogleLink::create([
            'hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/'.(900000 + $hotel->id), 'ga4_currency' => 'IDR',
            'gsc_site' => 'sc-domain:nandinibali.com',
            'date_mapping' => $mapped ? ['checkin' => 'checkin_date', 'checkout' => 'checkout_date', 'nights' => null, 'confirmed' => true] : null,
        ]);

        return [$owner, $hotel, $link];
    }

    private function traffic(Hotel $hotel, string $date, string $source, array $values = []): void
    {
        DB::table('ga4_traffic_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1($date.$source.json_encode($values)), 'source' => $source, 'medium' => 'referral',
            'country' => $values['country'] ?? 'Indonesia', 'landing_page' => $values['page'] ?? '/', 'device' => 'mobile',
            'sessions' => $values['sessions'] ?? 10, 'users' => $values['users'] ?? 9, 'engaged_sessions' => 5, 'key_events' => 0,
            'purchases' => $values['purchases'] ?? 0, 'revenue' => $values['revenue'] ?? 0,
        ]);
    }

    private function search(Hotel $hotel, string $query, int $clicks, int $impressions, float $position, string $page = 'https://nandinibali.com/rooms', string $country = 'idn'): void
    {
        DB::table('gsc_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1($query.$page.$country), 'query' => $query, 'page' => $page, 'country' => $country,
            'device' => 'MOBILE', 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions ? $clicks / $impressions : 0, 'position' => $position,
        ]);
    }

    public function test_only_private_teams_have_reports_and_other_teams_get_403(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertSee('AI traffic and bookings');

        $standard = User::factory()->withTeam(Team::factory()->create(['plan' => Plan::Starter]))->create();
        $own = Hotel::factory()->for($standard->currentTeam)->create(['setup_completed_at' => now()]);
        $this->actingAs($standard)->get(route('hotels.reports', $own))->assertForbidden();
        $this->actingAs($owner)->get(route('hotels.reports', $own))->assertForbidden();
    }

    public function test_the_empty_states_explain_what_is_missing(): void
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['setup_completed_at' => now()]);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('Google Analytics is not connected yet');
        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'report' => 'search']))->assertSee('Search Console is not connected yet');

        [$owner, $hotel, $link] = $this->privateHotel();
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('Your data is still syncing');

        $link->forceFill(['health' => ['receiving' => ['status' => 'red', 'message' => 'GA4 received no visits in the last 7 days.']]])->save();
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('GA4 is not receiving data')->assertSee('GA4 received no visits in the last 7 days.');

        $this->traffic($hotel, '2026-01-05', 'google');
        $link->forceFill(['health' => null])->save();
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('No GA4 data in this date range');
    }

    public function test_ai_sessions_purchases_and_revenue_come_from_ai_sources_only_and_shares_are_of_all_traffic(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 30, 'purchases' => 2, 'revenue' => 5000000]);
        $this->traffic($hotel, '2026-11-11', 'chat.openai.com', ['sessions' => 20, 'purchases' => 1, 'revenue' => 2500000]);
        $this->traffic($hotel, '2026-11-11', 'perplexity.ai', ['sessions' => 10]);
        $this->traffic($hotel, '2026-11-12', 'google', ['sessions' => 140, 'purchases' => 7, 'revenue' => 17500000]);
        $this->traffic($hotel, '2026-11-12', '(direct)', ['sessions' => 100]);

        $report = new AiTrafficReport($hotel, ReportPeriod::make('28d'));
        $now = $report->summary()['current'];

        $this->assertSame(60, $now['ai_sessions']);
        $this->assertSame(3, $now['ai_purchases']);
        $this->assertEquals(7500000, $now['ai_revenue']);
        $this->assertSame(300, $now['all_sessions']);
        $this->assertSame(10, $now['all_purchases']);
        $this->assertEqualsWithDelta(0.2, $now['session_share'], 0.0001);
        $this->assertEqualsWithDelta(0.3, $now['purchase_share'], 0.0001);
        $this->assertArrayNotHasKey('users', $now);
        $this->assertArrayNotHasKey('ai_users', $now);

        $platforms = collect($report->byPlatform())->keyBy('name');
        $this->assertSame(50, $platforms['ChatGPT']['sessions'], 'chatgpt.com and chat.openai.com are one platform');
        $this->assertSame(3, $platforms['ChatGPT']['purchases']);
        $this->assertSame(10, $platforms['Perplexity']['sessions']);
        $this->assertEqualsWithDelta(50 / 60, $platforms['ChatGPT']['share'], 0.0001);
        $this->assertFalse($platforms->has('Google'));

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertSee('20.0% of all sessions (300)', false)->assertSee('30.0% of all purchases (10)', false)->assertSee('IDR 7,500,000');
    }

    public function test_user_counts_are_never_added_up(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 5, 'users' => 9999999]);

        $report = new AiTrafficReport($hotel, ReportPeriod::make('28d'));

        $this->assertSame(5, $report->summary()['current']['ai_sessions']);
        $this->assertStringNotContainsString('9999999', json_encode([$report->summary(), $report->byPlatform(), $report->byCountry(), $report->byLandingPage(), $report->charts()]));
    }

    public function test_the_breakdowns_by_country_and_landing_page_and_the_date_range(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 12, 'country' => 'Australia', 'page' => '/rooms', 'purchases' => 1]);
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 8, 'country' => 'Germany', 'page' => '/spa']);
        $this->traffic($hotel, '2026-09-20', 'chatgpt.com', ['sessions' => 40, 'country' => 'Germany', 'page' => '/spa']);

        $short = new AiTrafficReport($hotel, ReportPeriod::make('28d'));
        $this->assertSame(['Australia', 'Germany'], array_column($short->byCountry(), 'name'));
        $this->assertSame([12, 8], array_column($short->byCountry(), 'sessions'));
        $this->assertSame(['/rooms', '/spa'], array_column($short->byLandingPage(), 'name'));

        $long = new AiTrafficReport($hotel, ReportPeriod::make('90d'));
        $this->assertSame(60, $long->summary()['current']['ai_sessions']);
        $this->assertSame(48, $long->byCountry()[0]['sessions']);
    }

    public function test_last_year_is_compared_only_when_the_data_reaches_back_that_far(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 30]);

        $this->assertNull((new AiTrafficReport($hotel, ReportPeriod::make('28d')))->summary()['previous']);
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('not available for this range yet');

        $this->traffic($hotel, '2025-11-10', 'chatgpt.com', ['sessions' => 20, 'purchases' => 1]);
        $this->traffic($hotel, '2025-11-10', 'google', ['sessions' => 80]);
        $this->traffic($hotel, '2025-10-28', 'google', ['sessions' => 0]); // the data starts before the old period
        $previous = (new AiTrafficReport($hotel, ReportPeriod::make('28d')))->summary()['previous'];

        $this->assertSame(20, $previous['ai_sessions']);
        $this->assertSame(100, $previous['all_sessions']);
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('+50% vs last year');

        $this->assertNull((new AiTrafficReport($hotel, ReportPeriod::make('12m')))->summary()['previous'], 'a year back needs two years of data');
    }

    public function test_the_range_picker_changes_the_period(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('Last 28 days (27 Oct 2026 to 23 Nov 2026)');
        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'range' => '90d']))->assertSee('Last 90 days (26 Aug 2026 to 23 Nov 2026)');
        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'range' => '12m']))->assertSee('Last 12 months');
        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'range' => 'nonsense']))->assertSee('Last 28 days');
    }

    public function test_the_fake_google_data_syncs_into_the_reports_without_any_live_call(): void
    {
        [$owner, $hotel, $link] = $this->privateHotel();
        $sync = app(GoogleSync::class);
        $from = now()->subDays(28)->toDateString();
        $to = now()->subDay()->toDateString();

        $sync->run($link, GoogleSync::GA4_TRAFFIC, $from, $to);
        $sync->run($link, GoogleSync::GA4_BOOKING_DATES, $from, $to);
        foreach (range(1, 20) as $days) {
            $sync->run($link, GoogleSync::GSC_DAY, now()->subDays($days)->toDateString(), now()->subDays($days)->toDateString());
        }

        $expected = (int) DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->whereIn('source', ['chatgpt.com', 'perplexity.ai'])->sum('sessions');
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, (new AiTrafficReport($hotel, ReportPeriod::make('28d')))->summary()['current']['ai_sessions']);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertSee('Demand by date')->assertSee('Demand by check-in date')->assertSee('id="demand-calendar"', false)->assertSee('Length of stay');
        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'report' => 'search']))->assertOk()->assertSee('Top queries')->assertSee('Seasonal query groups');
    }

    public function test_demand_by_check_in_date_and_by_length_of_stay(): void
    {
        [, $hotel] = $this->privateHotel();
        $rows = [
            ['2026-11-10', 'view_item', '2026-12-24', '2026-12-27', 3, 10],
            ['2026-11-10', 'begin_checkout', '2026-12-24', '2026-12-27', 3, 4],
            ['2026-11-11', 'purchase', '2026-12-24', '2026-12-28', 4, 1],
            ['2026-11-11', 'view_item', '2026-12-31', '2027-01-02', 2, 5],
            ['2026-11-11', 'view_item', '2026-10-01', '2026-10-03', 2, 99],
            ['2026-11-11', 'view_item', '2026-12-01', '2026-12-15', 14, 2],
        ];
        foreach ($rows as [$date, $event, $in, $out, $nights, $events]) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1(json_encode([$date, $event, $in, $out])), 'event_name' => $event, 'checkin' => $in, 'checkout' => $out, 'nights' => $nights, 'events' => $events]);
        }

        $demand = new BookingDemandReport($hotel, ReportPeriod::make('28d'));
        $byDate = collect($demand->byCheckin())->keyBy('date');

        $this->assertSame(['looked' => 10, 'started' => 4, 'booked' => 1, 'demand' => 14], collect($byDate['2026-12-24'])->only(['looked', 'started', 'booked', 'demand'])->all());
        $this->assertFalse($byDate->has('2026-10-01'), 'stays in the past are not demand');
        $this->assertSame('2026-12-24', $demand->topDates()[0]['date']);

        $stays = collect($demand->byStay())->keyBy('nights');
        $this->assertSame(10, $stays[3]['looked']);
        $this->assertSame(1, $stays[4]['booked']);
        $this->assertSame('10 or more nights', $stays[10]['name']);
        $this->assertSame(2, $stays[10]['looked']);
    }

    public function test_the_calendar_shades_each_night_and_lists_the_stays_behind_it(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $rows = [
            ['2026-11-10', 'view_item', '2026-12-10', '2026-12-13', 3, 300],
            ['2026-11-10', 'begin_checkout', '2026-12-10', '2026-12-13', 3, 100],
            ['2026-11-11', 'view_item', '2026-12-10', '2026-12-12', 2, 50],
            ['2026-11-11', 'purchase', '2026-12-10', '2026-12-13', 3, 5],
            ['2026-11-11', 'view_item', '2026-12-24', '2026-12-25', 1, 100],
            ['2026-11-11', 'view_item', '2026-10-01', '2026-10-02', 1, 999],
        ];
        foreach ($rows as [$date, $event, $in, $out, $nights, $events]) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1(json_encode([$date, $event, $in, $out])), 'event_name' => $event, 'checkin' => $in, 'checkout' => $out, 'nights' => $nights, 'events' => $events]);
        }

        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');
        $calendar = (new BookingDemandReport($hotel, ReportPeriod::make('28d')))->calendar();

        $this->assertSame(['2026-12-10', '2026-12-24'], array_keys($calendar['days']), 'past nights are not shown');
        $this->assertSame(450, $calendar['max']);
        $this->assertSame(['date' => '2026-12-10', 'demand' => 450], $calendar['top']);
        $this->assertSame('2026-11-24', $calendar['today']);

        $day = $calendar['days']['2026-12-10'];
        $this->assertSame([350, 100, 5, 450], [$day['l'], $day['s'], $day['b'], $day['d']]);
        $this->assertSame([[3, 300, 100, 5], [2, 50, 0, 0]], $day['stays'], 'the most asked stay length comes first');

        $page = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();
        $page->assertSee('Most searched check-in date ahead:')->assertSee('Thu 10 Dec')->assertSee('450 looks and started bookings');
        $page->assertSee('from all visitors, not only from AI assistants');
        $this->assertStringContainsString('"2026-12-10":{"l":350,"s":100,"b":5,"d":450', str_replace(['&quot;', '\u0022', '\/'], ['"', '"', '/'], $page->getContent()));
    }

    public function test_the_calendar_has_a_friendly_text_when_there_are_no_upcoming_stays(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'old', 'event_name' => 'view_item', 'checkin' => '2026-10-01', 'checkout' => null, 'nights' => null, 'events' => 4]);
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');

        $calendar = (new BookingDemandReport($hotel, ReportPeriod::make('28d')))->calendar();
        $this->assertSame(['days' => [], 'max' => 0, 'top' => null, 'today' => '2026-11-24'], $calendar);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('No upcoming stay dates yet.');
    }

    public function test_the_demand_section_has_tabs_a_csv_download_and_a_length_of_stay_chart(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 30, 'country' => 'Australia']);
        $this->traffic($hotel, '2026-11-10', 'perplexity.ai', ['sessions' => 10, 'country' => 'Germany']);
        $rows = [
            ['view_item', '2026-12-24', 3, 60], ['begin_checkout', '2026-12-24', 3, 20], ['view_item', '2026-12-26', 5, 15],
            ['view_item', '2026-12-30', 1, 5], ['view_item', '2026-12-31', 20, 2], ['purchase', '2026-12-24', 3, 99],
        ];
        foreach ($rows as $i => [$event, $in, $nights, $events]) {
            DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k'.$i, 'event_name' => $event, 'checkin' => $in, 'checkout' => null, 'nights' => $nights, 'events' => $events]);
        }

        $stays = (new BookingDemandReport($hotel, ReportPeriod::make('28d')))->stayHistogram();

        $this->assertSame(102, $stays['total'], 'looks and started bookings only; purchases are not demand');
        $this->assertSame(3, $stays['common']);
        $this->assertEqualsWithDelta(85 / 102, $stays['short_share'], 0.0001, 'stays of 1 to 3 nights');
        $bars = collect($stays['bars'])->keyBy('n');
        $this->assertSame([80, 15, 5, 2], [$bars[3]['demand'], $bars[5]['demand'], $bars[1]['demand'], $bars[15]['demand']]);
        $this->assertSame('15+', $bars[15]['label'], 'a 20 night stay is counted as 15 or more');
        $this->assertCount(15, $stays['bars']);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()
            ->assertSee('Popular dates')->assertSee('Every date')
            ->assertSee('Most common length of stay')->assertSee('3 nights')
            ->assertSee('Stays of 1 to 3 nights were 83% of the searches')
            ->assertDontSee('Download demand by date (CSV)')
            ->assertSee('Where AI visitors come from')->assertSee('Australia')->assertSee('30 · 75%')->assertSee('Germany');
    }

    public function test_the_length_of_stay_chart_is_left_out_when_no_stay_length_is_known(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');
        DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k', 'event_name' => 'view_item', 'checkin' => '2026-12-24', 'checkout' => null, 'nights' => null, 'events' => 9]);

        $this->assertNull((new BookingDemandReport($hotel, ReportPeriod::make('28d')))->stayHistogram());
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertDontSee('Most common length of stay')->assertSee('id="demand-calendar"', false);
    }

    public function test_without_mapped_booking_dates_the_report_shows_the_setup_hint(): void
    {
        [$owner, $hotel, $link] = $this->privateHotel(mapped: false);
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');
        $link->forceFill(['health' => ['booking_dates' => ['status' => 'red', 'message' => 'GA4 has no custom dimensions for booking dates.', 'developer' => 'Hi, could you send the stay dates to Google Analytics 4?']]])->save();

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))
            ->assertSee('Booking dates are not available yet')
            ->assertSee('GA4 has no custom dimensions for booking dates.')
            ->assertSee('could you send the stay dates')
            ->assertDontSee('id="demand-calendar"', false);
    }

    public function test_booking_dates_are_synced_once_the_fields_are_mapped_and_resynced_when_the_mapping_changes(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        Bus::fake();

        app(GoogleSync::class)->schedule($link);
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_BOOKING_DATES && $job->from === now()->subDay()->subMonthsNoOverflow(13)->toDateString());
        $this->assertNotNull($link->fresh()->booking_dates_synced_at);

        Bus::fake();
        app(GoogleSync::class)->schedule($link->fresh());
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_BOOKING_DATES && $job->from === now()->subDays(3)->toDateString());

        $link->forceFill(['date_mapping' => null])->save();
        Bus::fake();
        app(GoogleSync::class)->schedule($link->fresh());
        Bus::assertNotDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_BOOKING_DATES);
    }

    public function test_search_console_top_lists_and_totals(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->search($hotel, 'nandini bali', 80, 200, 1.2);
        $this->search($hotel, 'ubud villa', 10, 600, 6.0, 'https://nandinibali.com/villas', 'aus');

        $search = new SearchReport($hotel, ReportPeriod::make('28d'));

        $this->assertSame(['clicks' => 90, 'impressions' => 800, 'ctr' => 0.1125, 'position' => 4.8], $search->totals());
        $this->assertSame(['ubud villa', 'nandini bali'], array_column($search->queries(), 'name'));
        $this->assertSame(['https://nandinibali.com/villas', 'https://nandinibali.com/rooms'], array_column($search->pages(), 'name'));
        $this->assertSame(['AUS', 'IDN'], array_column($search->countries(), 'name'));

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'report' => 'search']))->assertSee('not available through the Search Console API');
    }

    public function test_low_click_rate_opportunities_and_seasonal_groups(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->search($hotel, 'ubud villa private pool', 6, 900, 7.5);     // opportunity
        $this->search($hotel, 'nandini bali', 300, 900, 1.1);              // good click rate
        $this->search($hotel, 'ubud resort cheap', 2, 900, 22.0);          // position too low
        $this->search($hotel, 'ubud spa', 1, 40, 5.0);                     // too few impressions
        $this->search($hotel, 'ubud hotel christmas 2026', 3, 500, 9.0);
        $this->search($hotel, 'nyepi retreat ubud', 1, 100, 4.0);
        $this->search($hotel, 'ubud hotel new year eve', 5, 300, 6.0);

        $search = new SearchReport($hotel, ReportPeriod::make('28d'));

        $this->assertSame(['ubud villa private pool', 'ubud hotel christmas 2026', 'ubud hotel new year eve', 'nyepi retreat ubud'], array_column($search->opportunities(), 'name'));

        $groups = collect($search->seasonalGroups())->keyBy('name');
        $this->assertSame(500, $groups['Christmas']['impressions']);
        $this->assertSame(300, $groups['New Year']['impressions']);
        $this->assertSame(100, $groups['Nyepi']['impressions']);
        $this->assertSame('ubud hotel christmas 2026', $groups['Christmas']['top']);
        $this->assertFalse($groups->has('Easter'));
    }

    public function test_findings_link_to_the_recommendations(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 25, 'page' => '/rooms']);
        $this->traffic($hotel, '2026-11-11', 'chatgpt.com', ['sessions' => 40, 'page' => '/offers', 'purchases' => 2]);

        $response = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();

        $response->assertSee('/rooms had 25 visits from AI assistants but no bookings')
            ->assertSee('/offers', false)
            ->assertSee(route('hotels.recommendations', $hotel), false);

        $response->assertDontSee('/offers had 40 visits');
    }

    public function test_each_table_exports_as_csv_and_formulas_are_neutralised(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com', ['sessions' => 12, 'page' => '=HYPERLINK("http://evil")']);
        $this->traffic($hotel, '2026-11-10', 'perplexity.ai', ['sessions' => 3, 'page' => '/spa']);

        $response = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'ai-pages', 'range' => '28d']));

        $response->assertOk()->assertDownload();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith('"Landing page","AI sessions"', $csv);
        $this->assertStringContainsString('\'=HYPERLINK', $csv);
        $this->assertStringNotContainsString("\n\"=HYPERLINK", $csv);

        foreach (['ai-platforms', 'ai-countries', 'demand-checkin', 'demand-stay', 'search-queries', 'search-pages', 'search-countries', 'search-seasonal', 'search-opportunities'] as $table) {
            $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, $table]))->assertOk();
        }

        $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'nope']))->assertNotFound();
        $standard = User::factory()->withTeam(Team::factory()->create(['plan' => Plan::Starter]))->create();
        $this->actingAs($standard)->get(route('hotels.reports.export', [$hotel, 'ai-pages']))->assertForbidden();
    }

    public function test_health_warnings_are_shown_above_a_report_that_has_data(): void
    {
        [$owner, $hotel, $link] = $this->privateHotel();
        $this->traffic($hotel, '2026-11-10', 'chatgpt.com');
        $link->forceFill(['health' => ['purchases' => ['status' => 'red', 'message' => 'No purchase events in the last 30 days, so bookings cannot be measured.']]])->save();

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('No purchase events in the last 30 days')->assertSee('Tracking check');
    }
}
