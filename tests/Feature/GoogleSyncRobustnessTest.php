<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Jobs\GoogleSelfCheckJob;
use App\Jobs\GoogleSyncJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Cron\CitevantaSchedule;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleGapHealer;
use App\Services\Google\GoogleSelfCheck;
use App\Services\Google\GoogleSync;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class GoogleSyncRobustnessTest extends TestCase
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

    /** One GA4 traffic row: date, source, medium, country, landing page, device, then the metrics. */
    private function row(string $date, string $source = 'chatgpt.com', string $medium = 'referral', string $country = 'Australia', string $page = '/', string $device = 'desktop', int $sessions = 5, int $purchases = 1): array
    {
        return ['dimensionValues' => array_map(fn ($value) => ['value' => $value], [str_replace('-', '', $date), $source, $medium, $country, $page, $device]),
            'metricValues' => array_map(fn ($value) => ['value' => (string) $value], [$sessions, $sessions, 1, 0, $purchases, 0])];
    }

    private function stored(int $hotelId, string $table, string $date): void
    {
        $base = ['hotel_id' => $hotelId, 'date' => $date];
        match ($table) {
            'ga4_traffic_daily' => DB::table($table)->insert($base + ['row_key' => sha1($date), 'source' => 'chatgpt.com', 'sessions' => 5, 'purchases' => 1]),
            'ga4_events_daily' => DB::table($table)->insert($base + ['event_name' => 'page_view', 'event_count' => 9]),
        };
    }

    // --- duplicates -----------------------------------------------------------

    public function test_the_ga4_tables_have_unique_keys_so_a_duplicate_row_cannot_be_stored(): void
    {
        [, $hotel] = $this->linkedHotel();
        $traffic = ['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1('a'), 'source' => 'chatgpt.com'];
        DB::table('ga4_traffic_daily')->insert($traffic);
        $events = ['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'event_name' => 'purchase'];
        DB::table('ga4_events_daily')->insert($events);
        $booking = ['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1('b'), 'event_name' => 'search_dates', 'checkin' => '2026-12-24'];
        DB::table('ga4_booking_dates_daily')->insert($booking);

        foreach (['ga4_traffic_daily' => $traffic, 'ga4_events_daily' => $events, 'ga4_booking_dates_daily' => $booking] as $table => $row) {
            try {
                DB::table($table)->insert($row);
                $this->fail("{$table} accepted a duplicate row");
            } catch (QueryException) {
                $this->assertSame(1, DB::table($table)->count(), $table);
            }
        }
    }

    public function test_reading_the_same_ga4_page_again_never_doubles_rows_and_each_dimension_makes_its_own_row(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        $rows = [
            $this->row('2026-11-10'),
            $this->row('2026-11-10', source: 'perplexity.ai'),
            $this->row('2026-11-10', medium: '(not set)'),
            $this->row('2026-11-10', country: 'Germany'),
            $this->row('2026-11-10', page: '/rooms'),
            $this->row('2026-11-10', device: 'mobile'),
            $this->row('2026-11-11'),
        ];
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->times(3)->andReturn(['rows' => $rows, 'rowCount' => 7]));
        $sync = app(GoogleSync::class);

        // The same page three times: twice in one batch (a retried job), once in a new batch (a re-sync).
        $sync->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-11', 0, 'one');
        $sync->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-11', 0, 'one');
        $sync->run($link, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-11', 0, 'two');

        $this->assertSame(7, DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->count(), 'one row per date, source, medium, country, landing page and device');
        $this->assertSame(35, (int) DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->sum('sessions'), 'sessions are counted once');
        $this->assertSame(7, DB::table('ga4_traffic_daily')->where('sync_batch', 'two')->count());
    }

    // --- job robustness ---------------------------------------------------------

    public function test_a_killed_or_released_job_never_runs_out_of_attempts_and_the_timeouts_fit_the_host(): void
    {
        $job = new GoogleSyncJob(1, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30');

        $this->assertSame(0, $job->tries, 'releases (rate limit, quota) and killed runs do not use up attempts: that was MaxAttemptsExceeded');
        $this->assertGreaterThan(0, $job->maxExceptions, 'real errors still stop the job');
        $this->assertTrue($job->retryUntil() > now()->addHours(24), 'it is retried for days, not a few minutes');
        $this->assertLessThan((int) config('citevanta.job_timeout'), $job->timeout, 'below the cron worker timeout');
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), (int) config('citevanta.job_timeout'), 'a hung call is retried only after it was killed');
        $this->assertSame('google', $job->queue);
    }

    public function test_the_same_sync_step_is_unique_and_different_steps_are_not(): void
    {
        $a = new GoogleSyncJob(1, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30', 0);

        $this->assertSame($a->uniqueId(), (new GoogleSyncJob(1, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30', 0, '2026-11-30', 'x'))->uniqueId());
        $this->assertNotSame($a->uniqueId(), (new GoogleSyncJob(1, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30', 10000))->uniqueId(), 'next page');
        $this->assertNotSame($a->uniqueId(), (new GoogleSyncJob(1, GoogleSync::GA4_EVENTS, '2026-11-01', '2026-11-30', 0))->uniqueId(), 'other type');
        $this->assertNotSame($a->uniqueId(), (new GoogleSyncJob(2, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30', 0))->uniqueId(), 'other hotel');
    }

    public function test_running_a_job_twice_is_safe_and_a_killed_page_runs_again_from_nothing(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => [$this->row('2026-11-10'), $this->row('2026-11-11')], 'rowCount' => 2]));
        Bus::fake();

        $job = new GoogleSyncJob($link->id, GoogleSync::GA4_TRAFFIC, '2026-11-10', '2026-11-11');
        $job->handle(app(GoogleSync::class));
        $job->handle(app(GoogleSync::class));

        $this->assertSame(2, DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->count());
        $this->assertSame(10, (int) DB::table('ga4_traffic_daily')->sum('sessions'));
    }

    public function test_a_step_that_ends_in_failure_is_recorded_without_secrets(): void
    {
        [, , $link] = $this->linkedHotel();
        $job = new GoogleSyncJob($link->id, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-30');

        $job->failed(new RuntimeException('boom ya29.a0AfH6SMBx-secretTOKEN -----BEGIN PRIVATE KEY-----MIIEvQ-----END PRIVATE KEY-----'));

        $error = (string) $link->fresh()->last_sync_error;
        $this->assertStringContainsString('ga4_traffic, 2026-11-01 to 2026-11-30', $error);
        $this->assertStringContainsString('queued again by the daily check', $error);
        $this->assertStringNotContainsString('ya29', $error);
        $this->assertStringNotContainsString('MIIEvQ', $error);
    }

    // --- gap healing --------------------------------------------------------------

    public function test_missing_days_in_the_last_13_months_are_queued_once_in_windows(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        // Stored: 1 Oct to 23 Nov except 10 to 12 Nov and 18 Nov; yesterday (23 Nov) is there.
        for ($day = Carbon::parse('2026-10-01'); $day->lte(Carbon::parse('2026-11-23')); $day->addDay()) {
            if (! in_array($day->toDateString(), ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-18'], true)) {
                $this->stored($hotel->id, 'ga4_traffic_daily', $day->toDateString());
                $this->stored($hotel->id, 'ga4_events_daily', $day->toDateString());
            }
        }
        Bus::fake();
        $healer = app(GoogleGapHealer::class);

        $this->assertSame([['2026-11-10', '2026-11-12'], ['2026-11-18', '2026-11-18']], $healer->missingRuns($link, 'ga4_traffic_daily'));
        $this->assertSame(4, $healer->heal($link), 'two runs, for traffic and for events');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->from === '2026-11-10' && $job->to === '2026-11-12' && $job->until === '2026-11-12');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_EVENTS && $job->from === '2026-11-18');

        $this->assertSame(0, $healer->heal($link), 'a run is asked for once a week, so days GA4 really has no data for are not requested every day');
    }

    public function test_trailing_days_and_long_gaps_are_split_and_nothing_is_queued_without_data_or_while_syncing(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        Bus::fake();
        $healer = app(GoogleGapHealer::class);

        $this->assertSame([], $healer->missingRuns($link, 'ga4_traffic_daily'), 'nothing stored: the first sync does that');

        $this->stored($hotel->id, 'ga4_traffic_daily', '2026-08-01');
        $runs = $healer->missingRuns($link, 'ga4_traffic_daily');
        $this->assertSame('2026-08-02', $runs[0][0]);
        $this->assertSame('2026-11-23', end($runs)[1], 'up to yesterday');
        foreach ($runs as [$from, $to]) {
            $this->assertLessThanOrEqual(GoogleSync::WINDOW_DAYS, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        }

        DB::table('jobs')->insert(['queue' => 'google', 'payload' => json_encode(['data' => ['command' => 's:6:"linkId";i:'.$link->id.';']]), 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->assertSame(0, $healer->heal($link), 'a running sync is not disturbed');
        Bus::assertNothingDispatched();

        $link->forceFill(['first_synced_at' => null])->save();
        DB::table('jobs')->delete();
        $this->assertSame(0, $healer->heal($link->fresh()), 'before the first sync finished there is nothing to heal');
    }

    public function test_the_daily_gap_check_is_scheduled_and_runs_as_a_command(): void
    {
        $this->assertContains('citevanta:google-heal', array_map(fn ($task) => $task->command, app(CitevantaSchedule::class)->tasks()));

        [, $hotel] = $this->linkedHotel();
        $this->stored($hotel->id, 'ga4_traffic_daily', '2026-11-20');
        Bus::fake();

        $this->artisan('citevanta:google-heal')->assertSuccessful();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->from === '2026-11-21' && $job->to === '2026-11-23');
    }

    // --- self-check ---------------------------------------------------------------

    private function liveNumbers(int $sessions, int $purchases): void
    {
        config(['services.google.fake' => false]);
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => [['metricValues' => [['value' => (string) $sessions], ['value' => (string) $purchases]]]], 'rowCount' => 1]));
    }

    public function test_the_two_percent_rule(): void
    {
        $this->assertFalse(GoogleSelfCheck::differs(1000, 1000));
        $this->assertFalse(GoogleSelfCheck::differs(1019, 1000));
        $this->assertFalse(GoogleSelfCheck::differs(981, 1000));
        $this->assertTrue(GoogleSelfCheck::differs(1021, 1000));
        $this->assertTrue(GoogleSelfCheck::differs(3, 13), '3 purchases stored against 13 in GA4');
        $this->assertTrue(GoogleSelfCheck::differs(1, 0));
        $this->assertFalse(GoogleSelfCheck::differs(0, 0));
    }

    public function test_a_difference_over_two_percent_warns_in_the_report_and_on_the_admin_page(): void
    {
        [$team, $hotel, $link] = $this->linkedHotel();
        $owner = User::factory()->withTeam($team)->create();
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1('x'), 'source' => 'google', 'sessions' => 32155, 'purchases' => 3]);
        $this->liveNumbers(25637, 13);

        $result = app(GoogleSelfCheck::class)->run($link);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame(['sessions' => 25637, 'purchases' => 13], $result['live']);
        $this->assertSame(['sessions' => 32155, 'purchases' => 3], $result['stored']);
        $warning = GoogleSelfCheck::warning($link->fresh());
        $this->assertStringContainsString('Data may be incomplete', $warning);

        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertSee('id="data-check-warning"', false)->assertSee('Data may be incomplete')->assertSee('32,155')->assertSee('25,637');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk()->assertSee('Data may be incomplete')->assertSee($hotel->name);
    }

    public function test_numbers_within_two_percent_show_no_warning(): void
    {
        [$team, $hotel, $link] = $this->linkedHotel();
        $owner = User::factory()->withTeam($team)->create();
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => sha1('x'), 'source' => 'google', 'sessions' => 1010, 'purchases' => 13]);
        $this->liveNumbers(1000, 13);

        $this->assertSame('ok', app(GoogleSelfCheck::class)->run($link)['status']);
        $this->assertNull(GoogleSelfCheck::warning($link->fresh()));
        $this->actingAs($owner)->get(route('hotels.reports', $hotel))->assertOk()->assertDontSee('Data may be incomplete');
    }

    public function test_the_self_check_runs_after_the_last_traffic_window_only_and_never_on_fake_data(): void
    {
        [, , $link] = $this->linkedHotel();
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => [], 'rowCount' => 0]));
        Bus::fake();
        $sync = app(GoogleSync::class);

        (new GoogleSyncJob($link->id, GoogleSync::GA4_TRAFFIC, '2026-10-01', '2026-10-31', 0, '2026-11-23'))->handle($sync);
        Bus::assertNotDispatched(GoogleSelfCheckJob::class);
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->from === '2026-11-01');

        (new GoogleSyncJob($link->id, GoogleSync::GA4_TRAFFIC, '2026-11-01', '2026-11-23', 0, '2026-11-23'))->handle($sync);
        Bus::assertDispatched(GoogleSelfCheckJob::class, fn ($job) => $job->linkId === $link->id);

        (new GoogleSyncJob($link->id, GoogleSync::GA4_EVENTS, '2026-11-01', '2026-11-23', 0, '2026-11-23'))->handle($sync);
        Bus::assertDispatchedTimes(GoogleSelfCheckJob::class, 1);

        config(['services.google.fake' => true]);
        $this->assertNull(app(GoogleSelfCheck::class)->run($link), 'no live request for fake data');
    }
}
