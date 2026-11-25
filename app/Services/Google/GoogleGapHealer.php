<?php

namespace App\Services\Google;

use App\Jobs\GoogleSyncJob;
use App\Models\HotelGoogleLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Once a day: compare the days we have stored with the days expected (from the oldest stored day, at most
 * ga4_first_sync_months back, to yesterday) and queue a job for every run of missing days, in windows of up to 31 days.
 * A run is asked for at most once a week, so a day that GA4 really has no data for is not requested forever.
 */
final class GoogleGapHealer
{
    private const TABLES = [
        GoogleSync::GA4_TRAFFIC => 'ga4_traffic_daily',
        GoogleSync::GA4_EVENTS => 'ga4_events_daily',
        GoogleSync::GA4_TOTALS => 'ga4_totals_daily',
    ];

    private const MAX_JOBS = 24;

    /**
     * @return int jobs queued
     */
    public function heal(HotelGoogleLink $link): int
    {
        if ($link->ga4_property === null || $link->first_synced_at === null || $this->syncIsRunning($link)) {
            return 0;
        }

        $jobs = 0;

        foreach (self::TABLES as $type => $table) {
            foreach ($this->missingRuns($link, $table) as [$from, $to]) {
                if ($jobs >= self::MAX_JOBS || ! Cache::add("google:heal:{$link->id}:{$type}:{$from}:{$to}", true, now()->addWeek())) {
                    continue;
                }

                GoogleSyncJob::dispatch($link->id, $type, $from, $to, 0, $to);
                $jobs++;
            }
        }

        return $jobs;
    }

    /**
     * Runs of consecutive missing days between the oldest stored day (or 13 months ago, if that is later) and yesterday.
     *
     * @return list<array{0: string, 1: string}> [from, to] in windows of at most 31 days
     */
    public function missingRuns(HotelGoogleLink $link, string $table): array
    {
        $yesterday = Carbon::yesterday();
        $earliestAllowed = $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 13));
        $stored = DB::table($table)->where('hotel_id', $link->hotel_id)->where('date', '>=', $earliestAllowed->toDateString())
            ->distinct()->pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString())->flip();

        if ($stored->isEmpty()) {
            return [];
        }

        $runs = [];
        $start = null;

        for ($day = Carbon::parse((string) $stored->keys()->min()); $day->lte($yesterday); $day = $day->copy()->addDay()) {
            $missing = ! $stored->has($day->toDateString());

            if ($missing && $start === null) {
                $start = $day->copy();
            }

            if ((! $missing || $day->eq($yesterday)) && $start !== null) {
                $end = $missing ? $day->copy() : $day->copy()->subDay();

                for ($from = $start->copy(); $from->lte($end); $from = $from->copy()->addDays(GoogleSync::WINDOW_DAYS)) {
                    $runs[] = [$from->toDateString(), GoogleSync::windowEnd($from->toDateString(), $end->toDateString())];
                }
                $start = null;
            }
        }

        return $runs;
    }

    private function syncIsRunning(HotelGoogleLink $link): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')->where('queue', 'google')->pluck('payload')
            ->contains(fn ($payload) => str_contains((string) (json_decode((string) $payload, true)['data']['command'] ?? ''), '"linkId";i:'.$link->id.';'));
    }
}
