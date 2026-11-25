<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Jobs\GoogleSelfCheckJob;
use App\Jobs\GoogleSyncJob;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleDiagnostics;
use App\Services\Google\GoogleSelfCheck;
use App\Services\Google\GoogleSync;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\ReportFindings;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\TrafficTotals;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "All sessions" come from daily totals (a GA4 request with only the date dimension), because the detailed rows
 * can add up to more than GA4 reports; the self-check compares totals and AI rows; "Sync now" reads the last 7 days.
 * Today is 24 Nov 2026.
 */
class GoogleTotalsAndSyncNowTest extends TestCase
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
        $link = HotelGoogleLink::create($link + ['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'ga4_currency' => 'IDR', 'first_synced_at' => now()->subWeek()]);

        return [$team, $hotel, $link];
    }

    private function detail(Hotel $hotel, string $date, string $source, int $sessions, int $purchases = 0, ?string $batch = null, string $page = '/'): void
    {
        DB::table('ga4_traffic_daily')->insert([
            'hotel_id' => $hotel->id, 'date' => $date, 'row_key' => sha1($date.$source.$page.$batch), 'source' => $source, 'medium' => 'referral', 'country' => 'Australia',
            'landing_page' => $page, 'device' => 'mobile', 'sessions' => $sessions, 'users' => $sessions, 'engaged_sessions' => 1, 'key_events' => 0,
            'purchases' => $purchases, 'revenue' => $purchases * 1000, 'sync_batch' => $batch,
        ]);
    }

    private function total(Hotel $hotel, string $date, int $sessions, int $purchases = 0): void
    {
        DB::table('ga4_totals_daily')->insert(['hotel_id' => $hotel->id, 'date' => $date, 'sessions' => $sessions, 'engaged_sessions' => 1, 'key_events' => 0, 'purchases' => $purchases, 'revenue' => $purchases * 1000]);
    }

    // --- all traffic comes from the daily totals ------------------------------------------

    public function test_all_sessions_purchases_and_revenue_come_from_the_daily_totals_not_the_sum_of_the_detailed_rows(): void
    {
        [, $hotel] = $this->linkedHotel();
        // The detailed rows add up to 37 sessions, but GA4 says the days had 25.
        $this->detail($hotel, '2026-11-10', 'chatgpt.com', 4, 1);
        $this->detail($hotel, '2026-11-10', 'google', 20, 2);
        $this->detail($hotel, '2026-11-11', 'google', 13, 1);
        $this->total($hotel, '2026-11-10', 15, 3);
        $this->total($hotel, '2026-11-11', 10, 1);

        $totals = (new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d', 'compare' => 'none'])))->totals(Carbon::parse('2026-10-27'), Carbon::parse('2026-11-23'));

        $this->assertSame([25, 4, 4000.0], [$totals['all_sessions'], $totals['all_purchases'], $totals['all_revenue']]);
        $this->assertSame([4, 1], [$totals['ai_sessions'], $totals['ai_purchases']], 'AI numbers still come from the detailed rows');
        $this->assertEqualsWithDelta(4 / 25, $totals['session_share'], 0.0001, 'the KPI percentage uses the true total');
        $this->assertEqualsWithDelta(1 / 4, $totals['purchase_share'], 0.0001);
    }

    public function test_days_without_a_daily_total_fall_back_to_their_detailed_rows_so_nothing_is_blank_after_an_upgrade(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->detail($hotel, '2026-11-10', 'google', 30);
        $this->detail($hotel, '2026-11-12', 'google', 8);
        $this->total($hotel, '2026-11-10', 22);

        $this->assertSame(30, TrafficTotals::sum($hotel->id, '2026-11-10', '2026-11-12')['sessions'], '22 (daily total) + 8 (no total for the 12th yet)');
        $this->assertFalse(TrafficTotals::isComplete($hotel->id, '2026-11-10', '2026-11-12'));
        $this->assertSame(['sessions' => 0, 'purchases' => 0, 'revenue' => 0.0], TrafficTotals::sum($hotel->id, '2026-09-01', '2026-09-30'));
    }

    public function test_findings_use_the_true_totals(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->detail($hotel, '2026-11-10', 'chatgpt.com', 5, 3);
        $this->detail($hotel, '2026-11-10', 'google', 200, 10);
        $this->total($hotel, '2026-11-10', 100, 13);
        $report = new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d', 'compare' => 'none']));

        $findings = app(ReportFindings::class)->forAiTraffic($hotel, $report);

        $this->assertStringContainsString('5.0% of your sessions but 23.1% of your bookings', $findings[0]['text'], '5 of 100 sessions and 3 of 13 purchases');
    }

    public function test_the_report_page_shows_the_true_all_sessions(): void
    {
        [$team, $hotel] = $this->linkedHotel();
        $owner = User::factory()->withTeam($team)->create();
        $this->detail($hotel, '2026-11-10', 'chatgpt.com', 4);
        $this->detail($hotel, '2026-11-10', 'google', 400);
        $this->total($hotel, '2026-11-10', 100);

        $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertOk()->assertSee('4.0% of all sessions (100)')->assertDontSee('(404)');
    }

    // --- the totals sync ----------------------------------------------------------------

    public function test_the_totals_sync_asks_for_the_date_dimension_only_and_replaces_by_date(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        $this->total($hotel, '2026-11-10', 999);
        $this->total($hotel, '2026-11-20', 7); // outside the window
        $captured = null;
        $row = fn (string $date, int $sessions, int $purchases) => ['dimensionValues' => [['value' => $date]], 'metricValues' => array_map(fn ($v) => ['value' => (string) $v], [$sessions, $sessions - 1, 2, $purchases, $purchases * 1000])];
        $this->mock(GoogleApi::class, function (MockInterface $mock) use (&$captured, $row): void {
            $mock->shouldReceive('runReport')->twice()->andReturnUsing(function (string $property, array $body) use (&$captured, $row): array {
                $captured = $body;

                return ['rows' => [$row('20261110', 15, 3), $row('20261111', 10, 1)], 'rowCount' => 2];
            });
        });

        app(GoogleSync::class)->run($link, GoogleSync::GA4_TOTALS, '2026-11-10', '2026-11-12', 0, 'b1');
        app(GoogleSync::class)->run($link, GoogleSync::GA4_TOTALS, '2026-11-10', '2026-11-12', 0, 'b2');

        $this->assertSame([['name' => 'date']], $captured['dimensions'], 'only the date dimension');
        $this->assertSame(['sessions', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue'], array_column($captured['metrics'], 'name'));
        $this->assertSame([15, 10, 7], DB::table('ga4_totals_daily')->where('hotel_id', $hotel->id)->orderBy('date')->pluck('sessions')->map(fn ($n) => (int) $n)->all(), 'replaced by date, no doubles, other days untouched');
        $this->assertSame(3, (int) DB::table('ga4_totals_daily')->where('date', '2026-11-10')->value('purchases'));
    }

    public function test_the_unique_key_keeps_one_total_per_hotel_and_day(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->total($hotel, '2026-11-10', 5);

        $this->expectException(QueryException::class);
        $this->total($hotel, '2026-11-10', 6);
    }

    public function test_schedule_resync_backfill_and_heal_include_the_totals(): void
    {
        [$team, $hotel, $link] = $this->linkedHotel();
        Bus::fake();

        $link->forceFill(['first_synced_at' => null])->save();
        app(GoogleSync::class)->schedule($link);
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TOTALS && $job->from === now()->subDay()->subMonthsNoOverflow(25)->toDateString());

        // Another hotel: the first jobs above never ran, so their unique locks (same step, same hotel) are still held.
        [, $other, $otherLink] = $this->linkedHotel();
        DB::table('ga4_totals_daily')->insert(['hotel_id' => $other->id, 'date' => '2026-06-01']);
        Bus::fake();

        $this->assertSame(1, app(GoogleSync::class)->backfill($otherLink), 'only the totals are stored, so only they are backfilled');
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->linkId === $otherLink->id && $job->type === GoogleSync::GA4_TOTALS && $job->until === '2026-05-31');
    }

    public function test_the_self_check_runs_after_the_totals_chain_too(): void
    {
        [, , $link] = $this->linkedHotel();
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturn(['rows' => [], 'rowCount' => 0]));
        Bus::fake();

        (new GoogleSyncJob($link->id, GoogleSync::GA4_TOTALS, '2026-11-01', '2026-11-23', 0, '2026-11-23'))->handle(app(GoogleSync::class));

        Bus::assertDispatched(GoogleSelfCheckJob::class, fn ($job) => $job->linkId === $link->id);
    }

    // --- the self-check compares totals and AI rows -----------------------------------------

    /** Live GA4: all traffic (no dimensions) and the sessions by source. */
    private function live(int $sessions, int $purchases, int $aiSessions, int $aiPurchases): void
    {
        config(['services.google.fake' => false]);
        $this->mock(GoogleApi::class, function (MockInterface $mock) use ($sessions, $purchases, $aiSessions, $aiPurchases): void {
            $mock->shouldReceive('runReport')->andReturnUsing(function (string $property, array $body) use ($sessions, $purchases, $aiSessions, $aiPurchases): array {
                if (isset($body['dimensions'])) {
                    return ['rows' => [
                        ['dimensionValues' => [['value' => 'chatgpt.com']], 'metricValues' => [['value' => (string) $aiSessions], ['value' => (string) $aiPurchases]]],
                        ['dimensionValues' => [['value' => 'google']], 'metricValues' => [['value' => '5000'], ['value' => '0']]],
                    ], 'rowCount' => 2];
                }

                return ['rows' => [['metricValues' => [['value' => (string) $sessions], ['value' => (string) $purchases]]]], 'rowCount' => 1];
            });
        });
    }

    public function test_the_self_check_compares_the_totals_and_the_ai_rows(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        $this->detail($hotel, '2026-11-10', 'chatgpt.com', 40, 2);
        $this->detail($hotel, '2026-11-10', 'google', 900, 0); // the detailed rows add up to 940 ...
        $this->total($hotel, '2026-11-10', 700, 2);             // ... but GA4 says 700

        $this->live(700, 2, 40, 2);
        $result = app(GoogleSelfCheck::class)->run($link);
        $this->assertSame('ok', $result['status'], 'totals and AI rows agree with GA4, although the detailed rows add up to more');
        $this->assertSame(['sessions' => 700, 'purchases' => 2], $result['stored']);
        $this->assertSame(['sessions' => 40, 'purchases' => 2], $result['ai_stored']);

        $this->live(900, 2, 40, 2);
        $this->assertSame('mismatch', app(GoogleSelfCheck::class)->run($link)['status'], 'the totals differ');

        $this->live(700, 2, 60, 2);
        $result = app(GoogleSelfCheck::class)->run($link);
        $this->assertSame('mismatch', $result['status'], 'the totals agree but the AI rows differ');
        $warning = (string) GoogleSelfCheck::warning($link->fresh());
        $this->assertStringContainsString('AI traffic: stored 40 sessions', $warning);
        $this->assertStringContainsString('GA4 shows 60', $warning);
    }

    // --- diagnostics: why can all sessions differ? -----------------------------------------

    /**
     * A fake GA4 that answers every diagnostics request with the right shape.
     *
     * @param  array{detail: list<int>, byDate: list<int>, none: int}  $sessions
     */
    private function fakeGa4(array $sessions): void
    {
        config(['services.google.fake' => false]);
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturnUsing(function (string $property, array $body) use ($sessions): array {
            $names = array_column($body['dimensions'] ?? [], 'name');
            $metrics = fn (int $first) => array_map(fn ($i) => ['value' => (string) ($i === 0 ? $first : 0)], range(0, max(count($body['metrics'] ?? []), 1) - 1));
            $row = fn (array $dimensions, int $first) => ['dimensionValues' => array_map(fn ($v) => ['value' => $v], $dimensions), 'metricValues' => $metrics($first)];

            $rows = match (true) {
                count($names) === 6 => array_map(fn ($n) => $row(array_fill(0, 6, 'x'), $n), $sessions['detail']),
                $names === ['date'] => array_map(fn ($n, $i) => $row(['2026111'.$i], $n), $sessions['byDate'], array_keys($sessions['byDate'])),
                $names === [] => [['metricValues' => $metrics($sessions['none'])]],
                default => [$row(array_fill(0, count($names), 'chatgpt.com'), 0)],
            };

            return ['rows' => $rows, 'rowCount' => count($rows)];
        }));
    }

    public function test_diagnostics_show_batches_dates_with_more_than_one_batch_and_the_three_live_totals(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->detail($hotel, '2026-11-10', 'google', 300, 0, 'old1');
        $this->detail($hotel, '2026-11-10', 'bing', 100, 0, 'new1');
        $this->detail($hotel, '2026-11-11', 'google', 50, 0, 'new1');
        $this->fakeGa4(['detail' => [300, 200], 'byDate' => [260, 240], 'none' => 500]);

        $output = implode("\n", app(GoogleDiagnostics::class)->run($hotel->load('googleLink'), '2026-11-10'));

        $this->assertStringContainsString('== 6. All traffic', $output);
        $this->assertStringContainsString('old1', $output);
        $this->assertStringContainsString('new1', $output);
        $this->assertStringContainsString('Dates with rows of more than one batch: 1 (2026-11-10)', $output);
        $this->assertStringContainsString('Stored detailed rows added up: 450 sessions', $output);
        $this->assertStringContainsString('detailed rows (2 rows) added up: 500 sessions', $output);
        $this->assertStringContainsString('date dimension only, added up:      500 sessions', $output);
        $this->assertStringContainsString('no dimensions (the range total):    500 sessions', $output);
    }

    public function test_diagnostics_say_so_when_the_detailed_rows_add_up_to_more_than_ga4(): void
    {
        [, $hotel] = $this->linkedHotel();
        $this->fakeGa4(['detail' => [37783], 'byDate' => [12000, 13637], 'none' => 25637]);

        $output = implode("\n", app(GoogleDiagnostics::class)->run($hotel->load('googleLink'), '2026-11-10'));

        $this->assertStringContainsString('the detailed rows add up to MORE than GA4 reports: GA4 is not additive here', $output);
    }

    // --- Re-sync and Backfill fill the daily totals for every month they copy ---------------------

    /** Live GA4 for a full run: one daily total per day of the asked window, nothing for the detailed rows and events. */
    private function dailyTotalsApi(): void
    {
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->andReturnUsing(function (string $property, array $body): array {
            if (array_column($body['dimensions'] ?? [], 'name') !== ['date'] || count($body['metrics']) !== 5) {
                return ['rows' => [], 'rowCount' => 0];
            }

            $rows = [];
            for ($day = Carbon::parse($body['dateRanges'][0]['startDate']); $day->lte(Carbon::parse($body['dateRanges'][0]['endDate'])); $day->addDay()) {
                $rows[] = ['dimensionValues' => [['value' => $day->format('Ymd')]], 'metricValues' => array_map(fn ($v) => ['value' => (string) $v], [100, 60, 3, 1, 2500])];
            }

            return ['rows' => $rows, 'rowCount' => count($rows)];
        }));
    }

    /**
     * Run the queued sync jobs one by one, as the queue worker does (each window queues the next; the test queue
     * would otherwise run 25 windows inside each other).
     */
    private function runQueuedSyncJobs(): void
    {
        $ran = 0;

        while (($jobs = Bus::dispatched(GoogleSyncJob::class)->values())->count() > $ran) {
            $jobs[$ran]->handle(app(GoogleSync::class));
            $ran++;
        }
    }

    public function test_re_sync_from_scratch_copies_the_daily_totals_for_every_month_of_the_history(): void
    {
        [$team, $hotel] = $this->linkedHotel();
        $this->dailyTotalsApi();
        $first = now()->subDay()->subMonthsNoOverflow(25);
        Bus::fake();

        $result = app(GoogleSync::class)->resyncTeam($team->id);
        $this->runQueuedSyncJobs();

        $this->assertSame(['hotels' => 1, 'jobs' => 3], $result, 'traffic, events and daily totals');
        $days = (int) $first->diffInDays(now()->subDay()) + 1;
        $this->assertSame($days, DB::table('ga4_totals_daily')->where('hotel_id', $hotel->id)->count(), 'one daily total for every day, none missing');
        $this->assertSame([$first->toDateString(), now()->subDay()->toDateString()], [DB::table('ga4_totals_daily')->min('date'), DB::table('ga4_totals_daily')->max('date')]);
        $months = DB::table('ga4_totals_daily')->get()->map(fn ($row) => substr($row->date, 0, 7))->unique();
        $this->assertGreaterThanOrEqual(25, $months->count(), 'every month of the 25 is there');
        $this->assertSame(100, (int) DB::table('ga4_totals_daily')->where('date', now()->subMonths(18)->toDateString())->value('sessions'));
    }

    public function test_backfill_copies_the_daily_totals_for_every_month_when_the_history_has_none(): void
    {
        [$team, $hotel] = $this->linkedHotel();
        // A team synced before daily totals existed: detailed rows from June, no totals.
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-06-01', 'row_key' => sha1('x'), 'source' => 'google', 'sessions' => 5]);
        $this->dailyTotalsApi();
        $first = now()->subDay()->subMonthsNoOverflow(25);
        Bus::fake();

        $result = app(GoogleSync::class)->backfillTeam($team->id);
        $this->runQueuedSyncJobs();

        $this->assertSame(['hotels' => 1, 'jobs' => 2], $result, 'the older traffic and the totals for every month');
        $days = (int) $first->diffInDays(now()->subDay()) + 1;
        $this->assertSame($days, DB::table('ga4_totals_daily')->where('hotel_id', $hotel->id)->count(), 'a total for every day, also for the months the traffic already had');
        $this->assertSame([$first->toDateString(), now()->subDay()->toDateString()], [DB::table('ga4_totals_daily')->min('date'), DB::table('ga4_totals_daily')->max('date')]);
        $this->assertSame(5, (int) DB::table('ga4_traffic_daily')->where('date', '2026-06-01')->value('sessions'), 'the stored traffic is untouched');
    }

    public function test_backfill_only_reads_the_older_totals_when_some_totals_exist_already(): void
    {
        [$team, $hotel] = $this->linkedHotel();
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-06-01', 'row_key' => sha1('x'), 'source' => 'google', 'sessions' => 5]);
        DB::table('ga4_totals_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-17', 'sessions' => 777]); // from Sync now
        Bus::fake();

        app(GoogleSync::class)->backfill($team->fresh()->hotels()->first()->googleLink);

        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TOTALS && $job->until === '2026-11-16', 'up to the day before the oldest total');
    }

    // --- Sync now -------------------------------------------------------------------------------

    public function test_sync_now_reads_only_the_last_7_days_of_every_ga4_type_and_never_the_history(): void
    {
        [$team, , $link] = $this->linkedHotel(['date_mapping' => ['checkin' => 'check_in_date', 'confirmed' => true]]);
        Bus::fake();

        $this->assertSame(4, app(GoogleSync::class)->syncRecent($link));

        foreach ([GoogleSync::GA4_TRAFFIC, GoogleSync::GA4_TOTALS, GoogleSync::GA4_EVENTS, GoogleSync::GA4_BOOKING_DATES] as $type) {
            Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === $type && $job->from === '2026-11-17' && $job->to === '2026-11-23' && $job->until === '2026-11-23');
        }
        Bus::assertNotDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GSC_DAY);
        $this->assertSame(['hotels' => 1, 'jobs' => 4], app(GoogleSync::class)->syncRecentTeam($team->id));
    }

    public function test_sync_now_replaces_those_days_by_date_and_leaves_older_rows_alone(): void
    {
        [, $hotel, $link] = $this->linkedHotel();
        $this->detail($hotel, '2026-10-01', 'google', 11, 0, 'history');
        $this->detail($hotel, '2026-11-20', 'google', 999, 0, 'stale');
        $row = ['dimensionValues' => array_map(fn ($v) => ['value' => $v], ['20261120', 'chatgpt.com', 'referral', 'Australia', '/', 'mobile']), 'metricValues' => array_map(fn ($v) => ['value' => (string) $v], [6, 6, 1, 0, 0, 0])];
        $this->mock(GoogleApi::class, fn (MockInterface $mock) => $mock->shouldReceive('runReport')->once()->andReturn(['rows' => [$row], 'rowCount' => 1]));

        (new GoogleSyncJob($link->id, GoogleSync::GA4_TRAFFIC, '2026-11-17', '2026-11-23', 0, '2026-11-23'))->handle(app(GoogleSync::class));

        $this->assertSame(11, (int) DB::table('ga4_traffic_daily')->where('date', '2026-10-01')->value('sessions'), 'the history is not touched');
        $this->assertSame(0, DB::table('ga4_traffic_daily')->where('source', 'google')->where('date', '2026-11-20')->count(), 'the stale row of those days is replaced');
        $this->assertSame(6, (int) DB::table('ga4_traffic_daily')->where('source', 'chatgpt.com')->value('sessions'));
    }

    public function test_the_sync_now_command_and_admin_button(): void
    {
        [$team, $hotel] = $this->linkedHotel();
        Bus::fake();

        $this->artisan('citevanta:google-sync-now', ['team' => (string) $team->id])->assertSuccessful();
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TOTALS);
        $this->artisan('citevanta:google-sync-now', ['team' => 'No such team'])->assertFailed();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk()->assertSee('Sync now (last 7 days)')->assertSee('Re-sync from scratch');
        $this->actingAs($admin)->post(route('admin.integrations.google.sync-now'), ['hotel' => $hotel->id])->assertRedirect(route('admin.integrations.google'))->assertSessionHas('status');
        $this->actingAs(User::factory()->withTeam($team)->create())->post(route('admin.integrations.google.sync-now'), ['hotel' => $hotel->id])->assertForbidden();
    }
}
