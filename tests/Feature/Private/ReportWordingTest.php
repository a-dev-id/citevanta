<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleSelfCheck;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\ReportFindings;
use App\Services\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wording on the Private plan reports: the data-check banner, the empty "Searches by date" and the landing page GA4 did not record.
 */
class ReportWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: HotelGoogleLink} */
    private function privateHotel(array $link = []): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandinibali.com', 'setup_completed_at' => now()]);
        $link = HotelGoogleLink::create($link + ['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'first_synced_at' => now()->subWeek()]);

        return [$owner, $hotel, $link];
    }

    private function traffic(Hotel $hotel, string $page, int $sessions, int $purchases = 0, string $date = '2026-11-10'): void
    {
        DB::table('ga4_traffic_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1($date.$page), 'source' => 'chatgpt.com', 'medium' => 'referral', 'country' => 'Australia',
            'landing_page' => $page, 'device' => 'mobile', 'sessions' => $sessions, 'users' => $sessions, 'engaged_sessions' => 1, 'key_events' => 0, 'purchases' => $purchases, 'revenue' => 0,
        ]);
    }

    private function mismatch(HotelGoogleLink $link): void
    {
        $link->forceFill(['data_check' => ['status' => 'mismatch', 'checked_at' => now()->toIso8601String(), 'from' => '2026-10-27', 'to' => '2026-11-23',
            'live' => ['sessions' => 25637, 'purchases' => 13], 'stored' => ['sessions' => 32155, 'purchases' => 3]]])->save();
    }

    // --- the data-check banner ---------------------------------------------------------

    public function test_hotel_users_get_a_short_message_without_numbers(): void
    {
        [$owner, $hotel, $link] = $this->privateHotel();
        $this->traffic($hotel, '/', 10);
        $this->mismatch($link);

        $page = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();

        $page->assertSee('id="data-check-warning"', false)->assertSee('Some totals are being checked and may change.');
        foreach (['Data may be incomplete', '32,155', '25,637', 'stored', 'Google Analytics shows'] as $hidden) {
            $page->assertDontSee($hidden);
        }
    }

    public function test_admins_see_the_full_numbers_stored_against_ga4(): void
    {
        [, $hotel, $link] = $this->privateHotel();
        $this->traffic($hotel, '/', 10);
        $this->mismatch($link);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('hotels.reports', $hotel))->assertOk()
            ->assertSee('Data may be incomplete')->assertSee('32,155')->assertSee('25,637')->assertDontSee('Some totals are being checked and may change.');
    }

    public function test_the_warning_text_depends_on_who_looks_and_is_empty_when_the_check_was_fine(): void
    {
        [, , $link] = $this->privateHotel();

        $this->assertNull(GoogleSelfCheck::warningFor($link, true));
        $this->assertNull(GoogleSelfCheck::warningFor($link, false));

        $this->mismatch($link);
        $this->assertStringContainsString('32,155', (string) GoogleSelfCheck::warningFor($link->fresh(), true));
        $this->assertSame('Some totals are being checked and may change.', GoogleSelfCheck::warningFor($link->fresh(), false));
        $this->assertNull(GoogleSelfCheck::warningFor(null, true));
    }

    // --- Searches by date -----------------------------------------------------------------

    public function test_without_rows_a_finished_sync_says_no_searches_yet_and_a_running_sync_says_syncing(): void
    {
        [$owner, $hotel, $link] = $this->privateHotel(['date_mapping' => ['checkin' => 'check_in_date', 'confirmed' => true]]);
        $this->traffic($hotel, '/', 10);

        $finished = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();
        $finished->assertSee('No searches recorded yet')->assertSee('Data appears 24–48 hours after the tracking tag is published.')->assertDontSee('Booking dates are syncing');

        DB::table('jobs')->insert(['queue' => 'google', 'payload' => json_encode(['data' => ['command' => 's:6:"linkId";i:'.$link->id.';']]), 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $running = $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk();
        $running->assertSee('Booking dates are syncing')->assertDontSee('No searches recorded yet');
    }

    public function test_another_hotels_sync_does_not_make_this_hotel_look_like_it_is_syncing(): void
    {
        [$owner, $hotel] = $this->privateHotel(['date_mapping' => ['checkin' => 'check_in_date', 'confirmed' => true]]);
        $this->traffic($hotel, '/', 10);
        DB::table('jobs')->insert(['queue' => 'google', 'payload' => json_encode(['data' => ['command' => 's:6:"linkId";i:99999;']]), 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertSee('No searches recorded yet')->assertDontSee('Booking dates are syncing');
    }

    public function test_rows_replace_the_empty_message_and_unmapped_fields_keep_their_own_message(): void
    {
        [$owner, $hotel] = $this->privateHotel(['date_mapping' => ['checkin' => 'check_in_date', 'confirmed' => true]]);
        $this->traffic($hotel, '/', 10);
        DB::table('ga4_booking_dates_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'k', 'event_name' => 'search_dates', 'checkin' => '2026-12-24', 'checkout' => null, 'nights' => null, 'events' => 5]);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertDontSee('No searches recorded yet')->assertSee('id="demand-calendar"', false);

        [$other, $unmapped] = $this->privateHotel();
        $this->traffic($unmapped, '/', 10);
        $this->actingAs($other)->get(route('hotels.reports', $unmapped))->assertOk()->assertSee('Booking dates are not available yet')->assertDontSee('No searches recorded yet');
    }

    // --- "Not recorded by GA4" --------------------------------------------------------------

    public function test_a_landing_page_ga4_did_not_record_is_called_not_recorded_by_ga4_in_every_table_and_the_csv(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '(not set)', 40);
        $this->traffic($hotel, '', 2);
        $this->traffic($hotel, '/rooms', 20, 1);
        $period = ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'], Carbon::parse('2026-10-01'));
        $report = new AiTrafficReport($hotel, $period);

        $pages = $report->tables()['ai-pages'];
        $this->assertSame(['Not recorded by GA4', '/rooms'], array_column($pages->rows, 'name'));
        $this->assertSame(['Not recorded by GA4', '/rooms'], array_column($report->byLandingPage(), 'name'), 'the findings list uses the same name');

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertOk()->assertSee('Not recorded by GA4')->assertDontSee('(not set)');

        $csv = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'ai-pages', 'compare' => 'none']))->streamedContent();
        $this->assertStringContainsString('Not recorded by GA4', $csv);
        $this->assertStringNotContainsString('(not set)', $csv);
    }

    public function test_other_tables_keep_their_names_and_findings_still_skip_the_unrecorded_page(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->traffic($hotel, '(not set)', 50);
        $report = new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d', 'compare' => 'none']));

        $this->assertSame('Australia', $report->tables()['ai-countries']->rows[0]['name']);
        $this->assertSame('ChatGPT', $report->tables()['ai-platforms']->rows[0]['name']);
        $findings = app(ReportFindings::class)->forAiTraffic($hotel, $report);
        $this->assertSame([], array_filter($findings, fn ($finding) => $finding['level'] === 'warning'), '50 visits to a page GA4 did not record is not a page to fix');
        $this->assertStringNotContainsString('Not recorded by GA4', json_encode($findings));
    }
}
