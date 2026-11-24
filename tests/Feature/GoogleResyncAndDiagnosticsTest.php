<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Jobs\GoogleSyncJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleDiagnostics;
use App\Services\Google\GoogleSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleResyncAndDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: Team, 1: Hotel, 2: HotelGoogleLink} */
    private function linkedHotel(array $link = []): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandinibali.com', 'setup_completed_at' => now()]);
        $link = HotelGoogleLink::create($link + ['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'first_synced_at' => now()->subWeek()]);

        return [$team, $hotel, $link];
    }

    private function row(string $date, string $source, int $sessions): array
    {
        return ['dimensionValues' => array_map(fn ($value) => ['value' => $value], [str_replace('-', '', $date), $source, 'referral', 'Australia', '/', 'desktop']),
            'metricValues' => array_map(fn ($value) => ['value' => (string) $value], [$sessions, $sessions, 1, 0, 0, 0])];
    }

    private function stored(int $hotelId, string $date, string $source, int $sessions, ?string $batch = null): void
    {
        DB::table('ga4_traffic_daily')->insert([
            'hotel_id' => $hotelId, 'date' => $date, 'row_key' => sha1($date.$source), 'source' => $source, 'medium' => 'referral', 'country' => 'Australia',
            'landing_page' => '/', 'device' => 'desktop', 'sessions' => $sessions, 'users' => $sessions, 'engaged_sessions' => 0, 'key_events' => 0,
            'purchases' => 0, 'revenue' => 0, 'sync_batch' => $batch,
        ]);
    }

    public function test_old_rows_stay_until_the_window_is_complete_and_are_then_replaced_by_date(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        config(['citevanta.google.ga4_page_size' => 2]);
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->stored($hotel->id, $date, 'old-source', 7);
        }
        $this->stored($hotel->id, '2026-11-20', 'outside-the-window', 3);

        $pages = [
            ['rows' => [$this->row('2026-11-10', 'chatgpt.com', 4), $this->row('2026-11-11', 'chatgpt.com', 5)], 'rowCount' => 3],
            ['rows' => [$this->row('2026-11-12', 'chatgpt.com', 6)], 'rowCount' => 3],
        ];
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->twice()->andReturn(...$pages));

        $sync = app(GoogleSync::class);
        $next = $sync->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-12', 0, 'batch-one');

        $this->assertSame(2, $next);
        $this->assertSame(3, DB::table('ga4_traffic_daily')->where('source', 'old-source')->count(), 'nothing old is removed while the window is still being copied');
        $this->assertSame(2, DB::table('ga4_traffic_daily')->where('source', 'chatgpt.com')->count());

        $this->assertNull($sync->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-12', 2, 'batch-one'));

        $this->assertSame(0, DB::table('ga4_traffic_daily')->where('source', 'old-source')->count(), 'replaced when the last page is saved');
        $this->assertSame([4, 5, 6], DB::table('ga4_traffic_daily')->where('source', 'chatgpt.com')->orderBy('date')->pluck('sessions')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(1, DB::table('ga4_traffic_daily')->where('source', 'outside-the-window')->count(), 'days outside the window are never touched');
    }

    public function test_a_window_that_stops_after_its_first_page_keeps_all_the_old_rows(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        config(['citevanta.google.ga4_page_size' => 1]);
        $this->stored($hotel->id, '2026-11-10', 'old-source', 7);
        $this->stored($hotel->id, '2026-11-11', 'old-source', 7);
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->once()->andReturn(['rows' => [$this->row('2026-11-10', 'chatgpt.com', 4)], 'rowCount' => 2]));

        app(GoogleSync::class)->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-11', 0, 'batch-one');

        $this->assertSame(2, DB::table('ga4_traffic_daily')->where('source', 'old-source')->count(), 'a failed or unfinished re-sync never leaves the reports emptier than before');
    }

    public function test_resync_starts_the_ga4_chains_again_without_deleting_anything(): void
    {
        [$team, $hotel, $link] = $this->linkedHotel(['gsc_site' => 'sc-domain:nandinibali.com', 'date_mapping' => ['checkin' => 'checkin_date'], 'booking_dates_synced_at' => now()->subDay()]);
        $this->stored($hotel->id, '2026-11-10', 'chatgpt.com', 4);
        Bus::fake();

        $result = app(GoogleSync::class)->resyncTeam($team->id);

        $this->assertSame(['hotels' => 1, 'jobs' => 3], $result, 'GA4 traffic, GA4 events and booking dates; Search Console is not touched');
        $first = now()->subDay()->subMonthsNoOverflow(13)->toDateString();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->from === $first && $job->until === now()->subDay()->toDateString());
        Bus::assertNotDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GSC_DAY);
        $this->assertSame(1, DB::table('ga4_traffic_daily')->count());
        $this->assertNotNull($link->fresh()->first_synced_at, 'the first-sync flag is set again by the new run');
    }

    public function test_the_resync_command_and_the_admin_button_start_it_for_the_team(): void
    {
        [$team] = $this->linkedHotel();
        Bus::fake();

        $this->artisan('citevanta:google-resync', ['team' => (string) $team->id])->assertSuccessful();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC);

        $this->artisan('citevanta:google-resync', ['team' => 'No such team'])->assertFailed();
    }

    public function test_admins_run_the_diagnostics_on_the_page_and_others_cannot(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->stored($hotel->id, '2026-11-10', 'chatgpt.com', 4);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk()
            ->assertSee('Run diagnostics')->assertSee('Re-sync from scratch')->assertSee(now()->subDays(10)->toDateString());

        $this->actingAs($admin)->post(route('admin.integrations.google.diagnose'), ['hotel' => $hotel->id, 'date' => '2026-11-10'])
            ->assertRedirect(route('admin.integrations.google'));
        $page = $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk();
        $page->assertSee('What is stored', false)->assertSee('ga4_traffic_daily', false)->assertSee('The exact request', false)->assertSee('orderBys', false);

        $owner = User::factory()->withTeam(Team::factory()->create())->create();
        $this->actingAs($owner)->post(route('admin.integrations.google.diagnose'), ['hotel' => $hotel->id])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.integrations.google.resync'), ['hotel' => $hotel->id])->assertForbidden();
    }

    public function test_the_admin_resync_button_asks_for_a_confirmation_and_starts_the_sync(): void
    {
        [, $hotel] = $this->linkedHotel();
        $admin = User::factory()->admin()->create();
        Bus::fake();

        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('onclick="return confirm(', false);
        $this->actingAs($admin)->post(route('admin.integrations.google.resync'), ['hotel' => $hotel->id])->assertRedirect(route('admin.integrations.google'))
            ->assertSessionHas('status');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_EVENTS);
    }

    public function test_live_diagnostics_show_purchase_numbers_side_by_side_and_never_a_token_or_key(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->stored($hotel->id, '2026-11-10', 'chatgpt.com', 4);
        config(['services.google.fake' => false]);
        $secret = '-----BEGIN PRIVATE KEY-----MIIEvQIBADANBgkqhkiG9w0BAQEFAASC-----END PRIVATE KEY-----';

        $this->mock(GoogleApi::class, function (MockInterface $mock) use ($secret): void {
            $mock->shouldReceive('runReport')->andReturnUsing(function (string $property, array $body) use ($secret): array {
                $metrics = array_column($body['metrics'], 'name');
                $rows = in_array('eventCount', $metrics, true)
                    ? [['dimensionValues' => [['value' => 'chatgpt.com']], 'metricValues' => [['value' => '13']]]]
                    : (isset($body['dimensions']) && count($body['dimensions']) === 1
                        ? [['dimensionValues' => [['value' => 'chatgpt.com']], 'metricValues' => [['value' => '40'], ['value' => '3'], ['value' => '2']]],
                            ['dimensionValues' => [['value' => '(other)']], 'metricValues' => [['value' => '9'], ['value' => '0'], ['value' => '0']]]]
                        : (isset($body['dimensions'])
                            ? [['dimensionValues' => [['value' => 'chatgpt.com'], ['value' => 'referral'], ['value' => 'Australia']], 'metricValues' => [['value' => '4'], ['value' => '0'], ['value' => '0']]]]
                            : [['metricValues' => array_map(fn ($v) => ['value' => $v], ['500', '3', '2', '9', '1200000', '1300000'])]]));

                return ['rows' => $rows, 'rowCount' => count($rows), 'metadata' => ['dataLossFromOtherRow' => true, 'note' => $secret.' ya29.a0AfH6SMBx-secretTOKEN Bearer abcdef123456789']];
            });
        });

        $output = implode("\n", app(GoogleDiagnostics::class)->run($hotel->load('googleLink'), '2026-11-10'));

        $this->assertStringContainsString('ecommercePurchases', $output);
        $this->assertStringContainsString('transactions', $output);
        $this->assertStringContainsString('purchase events', $output);
        $this->assertStringContainsString('GA4 now, all traffic', $output);
        $this->assertStringContainsString('"(other)" check: 9 sessions', $output);
        $this->assertStringContainsString('data loss from "(other)": YES', $output);
        $this->assertStringContainsString('2026-11-10', $output);
        foreach (['BEGIN PRIVATE', 'MIIEvQ', 'ya29', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_diagnostic_lines_never_carry_a_token_or_key_block(): void
    {
        $dirty = "failed: -----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkq\n-----END PRIVATE KEY----- token ya29.a0AfH6SMBx-secret_TOKEN jwt eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJ4In0.c2lnbmF0dXJl Bearer abcdef1234567890 access_token=zzzzzzzzzz1234";
        $clean = GoogleDiagnostics::safe($dirty);

        foreach (['MIIEvQ', 'ya29', 'eyJhbGci', 'abcdef1234567890', 'zzzzzzzzzz1234', 'BEGIN PRIVATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertStringContainsString('[private key removed]', $clean);
    }
}
