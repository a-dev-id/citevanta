<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use App\Services\Google\GoogleSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a report has nothing to show, in words the hotel can act on: Google is
 * not connected, the tracking health check is red, or the data is still being
 * copied. Reports read only our own synced tables.
 */
final class ReportAvailability
{
    /**
     * @return array{state: string, title: string, text: string, action: ?string, url: ?string}|null null when there is data to show
     */
    public static function ga4(Hotel $hotel, ReportPeriod $period): ?array
    {
        $link = $hotel->googleLink;

        if ($link === null || $link->ga4_property === null) {
            return self::notConnected('Google Analytics is not connected yet', 'Share your GA4 property with our agent account and pick it in Connect Google. Then your AI traffic and bookings appear here.', $hotel);
        }

        $table = DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id);

        if ((clone $table)->whereBetween('date', [$period->from->toDateString(), $period->to->toDateString()])->exists()) {
            return null;
        }

        $health = $link->health['receiving'] ?? null;
        if (($health['status'] ?? null) === 'red') {
            return self::unhealthy('GA4 is not receiving data', (string) $health['message'], $hotel);
        }

        return $table->exists() && ! self::syncRunning()
            ? self::state('no_data', 'No GA4 data in this date range', 'GA4 has no sessions for these dates. Try a longer range.', null, $hotel)
            : self::state('syncing', 'Your data is still syncing', 'The first copy of the GA4 history (up to 25 months) runs in the background and can take a few hours. Check back later.', null, $hotel);
    }

    /**
     * @return array{state: string, title: string, text: string, action: ?string, url: ?string}|null
     */
    public static function search(Hotel $hotel, ReportPeriod $period): ?array
    {
        $link = $hotel->googleLink;

        if ($link === null || $link->gsc_site === null) {
            return self::notConnected('Search Console is not connected yet', 'Share your Search Console property with our agent account and pick it in Connect Google. Then your Google Search reports appear here.', $hotel);
        }

        $table = DB::table('gsc_daily')->where('hotel_id', $hotel->id);

        if ((clone $table)->whereBetween('date', [$period->from->toDateString(), $period->to->toDateString()])->exists()) {
            return null;
        }

        $health = $link->health['search_console'] ?? null;
        if (($health['status'] ?? null) === 'red') {
            return self::unhealthy('Search Console is not working', (string) $health['message'], $hotel);
        }

        return $table->exists() && ! self::syncRunning()
            ? self::state('no_data', 'No Search Console data in this date range', 'Search Console has no impressions for these dates. Try a longer range.', null, $hotel)
            : self::state('syncing', 'Your data is still syncing', 'The first copy of 16 months of Search Console data runs in the background and can take a few hours. Check back later.', null, $hotel);
    }

    /**
     * Booking dates: the mapped GA4 fields, or the setup hint from the health check.
     *
     * @return array{state: string, title: string, text: string, action: ?string, url: ?string, developer: ?string}|null
     */
    public static function bookingDates(Hotel $hotel): ?array
    {
        $link = $hotel->googleLink;

        if ($link === null || $link->ga4_property === null) {
            return null; // the AI traffic message already explains it
        }

        if (DB::table('ga4_booking_dates_daily')->where('hotel_id', $hotel->id)->exists()) {
            return null;
        }

        $mapped = ! empty($link->date_mapping['checkin']);
        $health = $link->health['booking_dates'] ?? null;

        if (! $mapped) {
            return self::state('not_mapped', 'Booking dates are not available yet', (string) ($health['message'] ?? 'Run the tracking check in Connect Google. Demand by check-in date needs check-in and check-out dates in GA4.'), 'Open Connect Google', $hotel)
                + ['developer' => $health['developer'] ?? null];
        }

        if (GoogleSync::hasPendingJobs($link->id)) {
            return self::state('syncing', 'Booking dates are syncing', 'The check-in and check-out fields are mapped. The first copy runs in the background; check back later.', null, $hotel) + ['developer' => null];
        }

        // The sync has finished and GA4 has no stay dates yet.
        return self::state('no_searches', 'No searches recorded yet', 'Data appears 24–48 hours after the tracking tag is published.', null, $hotel) + ['developer' => null];
    }

    /**
     * Health checks that are not green, shown above a report that has data.
     *
     * @return list<array{status: string, message: string}>
     */
    public static function warnings(Hotel $hotel, array $checks): array
    {
        $health = (array) ($hotel->googleLink?->health ?? []);
        $warnings = [];

        foreach ($checks as $check) {
            $result = $health[$check] ?? null;

            if ($result !== null && $result['status'] !== 'green') {
                $warnings[] = ['status' => $result['status'], 'message' => (string) $result['message']];
            }
        }

        return $warnings;
    }

    /**
     * The first synced day and the last fully synced day of the GA4 data (the range the date picker allows).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function ga4Bounds(Hotel $hotel): array
    {
        $first = DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->min('date');

        return [$first === null ? null : Carbon::parse($first), self::ga4CompleteThrough($hotel)];
    }

    /**
     * The same for Search Console: the first and the newest stored day (never after yesterday).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function searchBounds(Hotel $hotel): array
    {
        $row = DB::table('gsc_daily')->where('hotel_id', $hotel->id)->selectRaw('min(date) as first, max(date) as last')->first();

        return [$row?->first === null ? null : Carbon::parse($row->first), $row?->last === null ? null : Carbon::parse($row->last)->min(Carbon::yesterday())];
    }

    /**
     * The last day GA4 data is fully copied. While jobs are waiting, rows arrive oldest first, so the newest
     * stored day may be half copied and is not counted. Null when nothing is stored yet.
     */
    public static function ga4CompleteThrough(Hotel $hotel): ?Carbon
    {
        $latest = DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->max('date');
        $yesterday = Carbon::yesterday();

        if ($latest === null) {
            return null;
        }

        if (self::syncRunning()) {
            return Carbon::parse($latest)->subDay()->min($yesterday);
        }

        return $hotel->googleLink?->last_sync_error !== null ? Carbon::parse($latest)->min($yesterday) : $yesterday;
    }

    /** Google sync jobs are waiting in the queue: the data is still arriving. */
    private static function syncRunning(): bool
    {
        return Schema::hasTable('jobs') && DB::table('jobs')->where('queue', 'google')->exists();
    }

    private static function notConnected(string $title, string $text, Hotel $hotel): array
    {
        return self::state('not_connected', $title, $text, 'Connect Google', $hotel);
    }

    private static function unhealthy(string $title, string $message, Hotel $hotel): array
    {
        return self::state('health_red', $title, $message.' Fix this in the tracking check, then the data fills in.', 'Open the tracking check', $hotel);
    }

    private static function state(string $state, string $title, string $text, ?string $action, Hotel $hotel): array
    {
        return ['state' => $state, 'title' => $title, 'text' => $text, 'action' => $action, 'url' => $action ? route('settings.google') : null];
    }
}
