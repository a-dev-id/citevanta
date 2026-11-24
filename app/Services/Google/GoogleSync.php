<?php

namespace App\Services\Google;

use App\Jobs\GoogleSyncJob;
use App\Models\HotelGoogleLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily copies of GA4 and Search Console data in our own tables, so
 * reports load fast and survive API outages. One API call per job:
 *
 *  - ga4_traffic: date x sessionSource x sessionMedium x country x landingPage x deviceCategory
 *    with sessions, totalUsers, engagedSessions, keyEvents, ecommercePurchases, purchaseRevenue
 *  - ga4_events: date x eventName with eventCount, eventValue
 *  - ga4_booking_dates: date x event x check-in (x check-out or nights), only when the booking date
 *    fields are mapped (the first time: 13 months, later the daily window)
 *  - gsc_day: one day of Search Console by query, page, country, device
 *
 * First sync: GA4 for the last 13 months, Search Console for 16 months (one job
 * per day). Then daily: the last 3 (GA4) and 5 (Search Console) days again,
 * because Google adds late data. Pages of results are further jobs.
 */
final class GoogleSync
{
    public const GA4_TRAFFIC = 'ga4_traffic';

    public const GA4_EVENTS = 'ga4_events';

    public const GSC_DAY = 'gsc_day';

    public const GA4_BOOKING_DATES = 'ga4_booking_dates';

    /** Events that carry the stay dates a guest looked at, started or booked. */
    public const BOOKING_EVENTS = ['view_item', 'begin_checkout', 'purchase'];

    private const TRAFFIC_DIMENSIONS = ['date', 'sessionSource', 'sessionMedium', 'country', 'landingPage', 'deviceCategory'];

    private const TRAFFIC_METRICS = ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue'];

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * Queue the jobs for one link: the first sync, or the daily one.
     *
     * @return int jobs queued
     */
    public function schedule(HotelGoogleLink $link): int
    {
        $first = $link->first_synced_at === null;
        $yesterday = Carbon::yesterday();
        $jobs = 0;

        if ($link->ga4_property !== null) {
            $from = $first
                ? $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 13))
                : $yesterday->copy()->subDays((int) config('citevanta.google.ga4_daily_days', 3) - 1);

            foreach ([self::GA4_TRAFFIC, self::GA4_EVENTS] as $type) {
                GoogleSyncJob::dispatch($link->id, $type, $from->toDateString(), $yesterday->toDateString());
                $jobs++;
            }
        }

        $mapping = (array) ($link->date_mapping ?? []);
        if ($link->ga4_property !== null && ! empty($mapping['checkin'])) {
            // Mapped after the first sync (the health check detects it later): backfill once.
            $from = $link->booking_dates_synced_at === null
                ? $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 13))
                : $yesterday->copy()->subDays((int) config('citevanta.google.ga4_daily_days', 3) - 1);

            GoogleSyncJob::dispatch($link->id, self::GA4_BOOKING_DATES, $from->toDateString(), $yesterday->toDateString());
            $jobs++;
            $link->booking_dates_synced_at = now();
        }

        if ($link->gsc_site !== null) {
            $from = $first
                ? $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.gsc_first_sync_months', 16))
                : $yesterday->copy()->subDays((int) config('citevanta.google.gsc_daily_days', 5) - 1);

            for ($day = $from->copy(); $day->lte($yesterday); $day = $day->addDay()) {
                GoogleSyncJob::dispatch($link->id, self::GSC_DAY, $day->toDateString(), $day->toDateString());
                $jobs++;
            }
        }

        $link->forceFill(['first_synced_at' => $link->first_synced_at ?? now()])->save();

        return $jobs;
    }

    /**
     * Run one sync call and store its rows.
     *
     * @return int|null the offset of the next page, or null when done
     *
     * @throws GoogleApiException
     */
    public function run(HotelGoogleLink $link, string $type, string $from, string $to, int $offset = 0): ?int
    {
        $next = match ($type) {
            self::GA4_TRAFFIC => $this->ga4Traffic($link, $from, $to, $offset),
            self::GA4_EVENTS => $this->ga4Events($link, $from, $to, $offset),
            self::GA4_BOOKING_DATES => $this->ga4BookingDates($link, $from, $to, $offset),
            self::GSC_DAY => $this->gscDay($link, $from, $offset),
            default => null,
        };

        $link->forceFill(['last_synced_at' => now(), 'last_sync_error' => null])->save();

        return $next;
    }

    private function ga4Traffic(HotelGoogleLink $link, string $from, string $to, int $offset): ?int
    {
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => array_map(fn ($name) => ['name' => $name], self::TRAFFIC_DIMENSIONS),
            'metrics' => array_map(fn ($name) => ['name' => $name], self::TRAFFIC_METRICS),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ]);

        if ($offset === 0) {
            DB::table('ga4_traffic_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from, $to])->delete();
        }

        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            [$date, $source, $medium, $country, $page, $device] = array_map(fn ($v) => (string) ($v['value'] ?? ''), $row['dimensionValues']);
            [$sessions, $users, $engaged, $keyEvents, $purchases, $revenue] = array_map(fn ($v) => (float) ($v['value'] ?? 0), $row['metricValues']);
            $page = mb_substr($page, 0, 500);
            $date = self::date($date);

            $rows[] = [
                'hotel_id' => $link->hotel_id, 'date' => $date,
                'row_key' => sha1(implode('|', [$date, $source, $medium, $country, $page, $device])),
                'source' => mb_substr($source, 0, 190), 'medium' => mb_substr($medium, 0, 100), 'country' => mb_substr($country, 0, 100),
                'landing_page' => $page, 'device' => mb_substr($device, 0, 30),
                'sessions' => (int) $sessions, 'users' => (int) $users, 'engaged_sessions' => (int) $engaged,
                'key_events' => (int) $keyEvents, 'purchases' => (int) $purchases, 'revenue' => round($revenue, 2),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ga4_traffic_daily')->upsert($chunk, ['hotel_id', 'row_key'], ['sessions', 'users', 'engaged_sessions', 'key_events', 'purchases', 'revenue']);
        }

        return $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;
    }

    private function ga4Events(HotelGoogleLink $link, string $from, string $to, int $offset): ?int
    {
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount'], ['name' => 'eventValue']],
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ]);

        if ($offset === 0) {
            DB::table('ga4_events_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from, $to])->delete();
        }

        $rows = array_map(fn (array $row) => [
            'hotel_id' => $link->hotel_id,
            'date' => self::date((string) $row['dimensionValues'][0]['value']),
            'event_name' => mb_substr((string) $row['dimensionValues'][1]['value'], 0, 120),
            'event_count' => (int) $row['metricValues'][0]['value'],
            'event_value' => round((float) $row['metricValues'][1]['value'], 2),
        ], (array) ($data['rows'] ?? []));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ga4_events_daily')->upsert($chunk, ['hotel_id', 'date', 'event_name'], ['event_count', 'event_value']);
        }

        return $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;
    }

    private function ga4BookingDates(HotelGoogleLink $link, string $from, string $to, int $offset): ?int
    {
        $mapping = (array) ($link->date_mapping ?? []);

        if (empty($mapping['checkin'])) {
            return null;
        }

        $stay = ! empty($mapping['checkout']) ? $mapping['checkout'] : ($mapping['nights'] ?? null);
        $dimensions = array_values(array_filter(['date', 'eventName', 'customEvent:'.$mapping['checkin'], $stay ? 'customEvent:'.$stay : null]));
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => array_map(fn ($name) => ['name' => $name], $dimensions),
            'metrics' => [['name' => 'eventCount']],
            'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => self::BOOKING_EVENTS]]],
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ]);

        if ($offset === 0) {
            DB::table('ga4_booking_dates_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from, $to])->delete();
        }

        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $values = array_map(fn ($v) => (string) ($v['value'] ?? ''), $row['dimensionValues']);
            $checkin = self::validDate($values[2] ?? '');

            if ($checkin === null) {
                continue;
            }

            $checkout = ! empty($mapping['checkout']) ? self::validDate($values[3] ?? '') : null;
            $nights = match (true) {
                $checkout !== null => (int) Carbon::parse($checkin)->diffInDays(Carbon::parse($checkout)),
                empty($mapping['checkout']) && isset($values[3]) && ctype_digit($values[3]) => (int) $values[3],
                default => null,
            };
            $nights = $nights !== null && $nights >= 1 && $nights <= 90 ? $nights : null;
            $date = self::date($values[0]);
            $key = sha1(implode('|', [$date, $values[1], $checkin, $checkout, $nights]));

            $rows[$key] = [
                'hotel_id' => $link->hotel_id, 'date' => $date, 'row_key' => $key, 'event_name' => mb_substr($values[1], 0, 60),
                'checkin' => $checkin, 'checkout' => $checkout, 'nights' => $nights,
                'events' => ($rows[$key]['events'] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('ga4_booking_dates_daily')->upsert($chunk, ['hotel_id', 'row_key'], ['events']);
        }

        return $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;
    }

    /** "2026-12-24" or "20261224" as Y-m-d, or null when it is not a real date ("(not set)", junk). */
    private static function validDate(string $value): ?string
    {
        $value = self::date(trim($value));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) && $year >= 2000 ? $value : null;
    }

    private function gscDay(HotelGoogleLink $link, string $day, int $offset): ?int
    {
        $limit = (int) config('citevanta.google.gsc_page_size', 25000);
        $data = $this->api->searchAnalytics((string) $link->gsc_site, [
            'startDate' => $day,
            'endDate' => $day,
            'dimensions' => ['query', 'page', 'country', 'device'],
            'type' => 'web',
            'rowLimit' => $limit,
            'startRow' => $offset,
            'dataState' => 'all',
        ]);

        if ($offset === 0) {
            DB::table('gsc_daily')->where('hotel_id', $link->hotel_id)->where('date', $day)->delete();
        }

        $rows = array_map(function (array $row) use ($link, $day) {
            [$query, $page, $country, $device] = array_map('strval', $row['keys']);
            $query = mb_substr($query, 0, 500);
            $page = mb_substr($page, 0, 500);

            return [
                'hotel_id' => $link->hotel_id, 'date' => $day,
                'row_key' => sha1(implode('|', [$day, $query, $page, $country, $device])),
                'query' => $query, 'page' => $page, 'country' => mb_substr($country, 0, 10), 'device' => mb_substr($device, 0, 20),
                'clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'],
                'ctr' => round((float) $row['ctr'], 6), 'position' => round((float) $row['position'], 2),
            ];
        }, (array) ($data['rows'] ?? []));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('gsc_daily')->upsert($chunk, ['hotel_id', 'row_key'], ['clicks', 'impressions', 'ctr', 'position']);
        }

        return count($rows) === $limit ? $offset + $limit : null;
    }

    /** GA4 dates are "20261120". */
    private static function date(string $value): string
    {
        return preg_match('/^\d{8}$/', $value) ? substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2) : $value;
    }
}
