<?php

namespace App\Services\Google;

use App\Jobs\GoogleSyncJob;
use App\Models\HotelGoogleLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily copies of GA4 and Search Console data in our own tables, so
 * reports load fast and survive API outages. One API call per job:
 *
 *  - ga4_traffic: date x sessionSource x sessionMedium x country x landingPage x deviceCategory
 *    with sessions, totalUsers, engagedSessions, keyEvents, ecommercePurchases, purchaseRevenue
 *  - ga4_events: date x eventName with eventCount, eventValue
 *  - ga4_booking_dates: date x event x check-in (x check-out or nights), only when the booking date
 *    fields are mapped (the first time: ga4_first_sync_months, later the daily window)
 *  - gsc_day: one day of Search Console by query, page, country, device
 *
 * First sync: GA4 for the last 25 months (ga4_first_sync_months), Search Console for 16 months (one job
 * per day). Then daily: the last 3 (GA4) and 5 (Search Console) days again,
 * because Google adds late data. Pages of results are further jobs.
 */
final class GoogleSync
{
    public const GA4_TRAFFIC = 'ga4_traffic';

    public const GA4_EVENTS = 'ga4_events';

    public const GSC_DAY = 'gsc_day';

    public const GA4_BOOKING_DATES = 'ga4_booking_dates';

    private const TRAFFIC_DIMENSIONS = ['date', 'sessionSource', 'sessionMedium', 'country', 'landingPage', 'deviceCategory'];

    private const TRAFFIC_METRICS = ['sessions', 'totalUsers', 'engagedSessions', 'keyEvents', 'ecommercePurchases', 'purchaseRevenue'];

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * Queue the jobs for one link: the first sync, or the daily one.
     *
     * @return int jobs queued
     */
    public function schedule(HotelGoogleLink $link, bool $includeSearchConsole = true): int
    {
        $first = $link->first_synced_at === null;
        $yesterday = Carbon::yesterday();
        $jobs = 0;

        if ($link->ga4_property !== null) {
            $from = $first
                ? $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 13))
                : $yesterday->copy()->subDays((int) config('citevanta.google.ga4_daily_days', 3) - 1);

            foreach ([self::GA4_TRAFFIC, self::GA4_EVENTS] as $type) {
                // One chain per type, oldest window first: each window is read only after the one before it is complete.
                GoogleSyncJob::dispatch($link->id, $type, $from->toDateString(), self::windowEnd($from->toDateString(), $yesterday->toDateString()), 0, $yesterday->toDateString());
                $jobs++;
            }
        }

        $mapping = (array) ($link->date_mapping ?? []);
        if ($link->ga4_property !== null && ! empty($mapping['checkin'])) {
            // Mapped after the first sync (the health check detects it later): backfill once.
            $from = $link->booking_dates_synced_at === null
                ? $yesterday->copy()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 13))
                : $yesterday->copy()->subDays((int) config('citevanta.google.ga4_daily_days', 3) - 1);

            GoogleSyncJob::dispatch($link->id, self::GA4_BOOKING_DATES, $from->toDateString(), self::windowEnd($from->toDateString(), $yesterday->toDateString()), 0, $yesterday->toDateString());
            $jobs++;
            $link->booking_dates_synced_at = now();
        }

        if ($includeSearchConsole && $link->gsc_site !== null) {
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

    /** Days in one GA4 request window: smaller windows keep GA4 from folding rows into "(other)". */
    public const WINDOW_DAYS = 31;

    /**
     * The exact GA4 runReport body of the traffic sync.
     *
     * @return array<string, mixed>
     */
    public static function trafficRequest(string $from, string $to, int $limit, int $offset = 0): array
    {
        return [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => array_map(fn ($name) => ['name' => $name], self::TRAFFIC_DIMENSIONS),
            'metrics' => array_map(fn ($name) => ['name' => $name], self::TRAFFIC_METRICS),
            'orderBys' => self::orderBy(self::TRAFFIC_DIMENSIONS),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ];
    }

    /** Last day of the window that starts on $from. */
    public static function windowEnd(string $from, string $until): string
    {
        return Carbon::parse($from)->addDays(self::WINDOW_DAYS - 1)->min(Carbon::parse($until))->toDateString();
    }

    /**
     * Sort GA4 rows by every dimension, oldest date first, so pages are stable and complete up to a date.
     *
     * @param  list<string>  $dimensions
     * @return list<array{dimension: array{dimensionName: string}}>
     */
    private static function orderBy(array $dimensions): array
    {
        return array_map(fn (string $name) => ['dimension' => ['dimensionName' => $name]], $dimensions);
    }

    /**
     * Copy the GA4 history again with the ordered, windowed method (Search Console is not touched). The stored rows stay and are replaced by date
     * as each window arrives, so the reports are never empty.
     *
     * @return int jobs queued
     */
    public function resync(HotelGoogleLink $link): int
    {
        $link->forceFill(['first_synced_at' => null, 'booking_dates_synced_at' => null, 'last_sync_error' => null])->save();

        return $this->schedule($link->fresh(), false);
    }

    /**
     * Read only the older days: from the oldest wanted day (ga4_first_sync_months back) up to the day before the
     * oldest stored day, with the same ordered, windowed method; each window replaces its own dates, so nothing
     * newer is touched. For teams that were synced with fewer months.
     *
     * @return int job chains queued
     */
    public function backfill(HotelGoogleLink $link): int
    {
        if ($link->ga4_property === null) {
            return 0;
        }

        $wanted = Carbon::yesterday()->subMonthsNoOverflow((int) config('citevanta.google.ga4_first_sync_months', 25));
        $chains = 0;
        $tables = [self::GA4_TRAFFIC => 'ga4_traffic_daily', self::GA4_EVENTS => 'ga4_events_daily'];

        if (! empty(($link->date_mapping ?? [])['checkin'])) {
            $tables[self::GA4_BOOKING_DATES] = 'ga4_booking_dates_daily';
        }

        foreach ($tables as $type => $table) {
            $oldest = DB::table($table)->where('hotel_id', $link->hotel_id)->min('date');

            if ($oldest === null) {
                continue; // nothing stored yet: the normal first sync reads everything
            }

            $until = Carbon::parse($oldest)->subDay();

            if ($until->lt($wanted)) {
                continue; // already reaches back far enough
            }

            GoogleSyncJob::dispatch($link->id, $type, $wanted->toDateString(), self::windowEnd($wanted->toDateString(), $until->toDateString()), 0, $until->toDateString());
            $chains++;
        }

        return $chains;
    }

    /**
     * Backfill every linked hotel of a team.
     *
     * @return array{hotels: int, jobs: int}
     */
    public function backfillTeam(int $teamId): array
    {
        $links = HotelGoogleLink::query()->where('team_id', $teamId)->whereNotNull('ga4_property')->get();

        return ['hotels' => $links->count(), 'jobs' => $links->sum(fn (HotelGoogleLink $link) => $this->backfill($link))];
    }

    /**
     * Re-sync every linked hotel of a team.
     *
     * @return array{hotels: int, jobs: int}
     */
    public function resyncTeam(int $teamId): array
    {
        $links = HotelGoogleLink::query()->where('team_id', $teamId)->whereNotNull('ga4_property')->get();

        return ['hotels' => $links->count(), 'jobs' => $links->sum(fn (HotelGoogleLink $link) => $this->resync($link))];
    }

    /**
     * Run one sync call and store its rows.
     *
     * @return int|null the offset of the next page, or null when done
     *
     * @throws GoogleApiException
     */
    public function run(HotelGoogleLink $link, string $type, string $from, string $to, int $offset = 0, ?string $batch = null): ?int
    {
        $next = match ($type) {
            self::GA4_TRAFFIC => $this->ga4Traffic($link, $from, $to, $offset, $batch),
            self::GA4_EVENTS => $this->ga4Events($link, $from, $to, $offset, $batch),
            self::GA4_BOOKING_DATES => $this->ga4BookingDates($link, $from, $to, $offset, $batch),
            self::GSC_DAY => $this->gscDay($link, $from, $offset),
            default => null,
        };

        $link->forceFill(['last_synced_at' => now(), 'last_sync_error' => null])->save();

        return $next;
    }

    private function ga4Traffic(HotelGoogleLink $link, string $from, string $to, int $offset, ?string $batch): ?int
    {
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);
        $data = $this->api->runReport((string) $link->ga4_property, self::trafficRequest($from, $to, $limit, $offset));
        $this->noteOtherRow($link, 'ga4_traffic', $from, $to, $data);

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

        $next = $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;

        $this->store('ga4_traffic_daily', $rows, ['hotel_id', 'row_key'], ['sessions', 'users', 'engaged_sessions', 'key_events', 'purchases', 'revenue'], $link->hotel_id, $from, $to, $batch, $next === null);

        return $next;
    }

    /**
     * Save one page. Every row is stamped with the batch of this copy of the window. When the last page is saved,
     * rows of the window that belong to another batch (older copies, or days GA4 no longer has) are removed. Until
     * then the old rows stay, so reports never go empty; rows with the same key are updated in place.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    private function store(string $table, array $rows, array $uniqueBy, array $update, int $hotelId, string $from, string $to, ?string $batch, bool $last): void
    {
        DB::transaction(function () use ($table, $rows, $uniqueBy, $update, $hotelId, $from, $to, $batch, $last): void {
            $rows = array_map(fn (array $row) => $row + ['sync_batch' => $batch], $rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->upsert($chunk, $uniqueBy, array_merge($update, ['sync_batch']));
            }

            if ($last && $batch !== null) {
                DB::table($table)->where('hotel_id', $hotelId)->whereBetween('date', [$from, $to])
                    ->where(fn ($query) => $query->whereNull('sync_batch')->orWhere('sync_batch', '!=', $batch))->delete();
            }
        });
    }

    /**
     * GA4 folds rows into "(other)" when a report has too many combinations; say so in the log (no secrets in it).
     *
     * @param  array<string, mixed>  $data
     */
    private function noteOtherRow(HotelGoogleLink $link, string $type, string $from, string $to, array $data): void
    {
        if (! empty($data['metadata']['dataLossFromOtherRow'])) {
            Log::warning('GA4 grouped some rows as "(other)"', ['hotel_id' => $link->hotel_id, 'type' => $type, 'from' => $from, 'to' => $to]);
        }
    }

    private function ga4Events(HotelGoogleLink $link, string $from, string $to, int $offset, ?string $batch): ?int
    {
        $limit = (int) config('citevanta.google.ga4_page_size', 10000);
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount'], ['name' => 'eventValue']],
            'orderBys' => self::orderBy(['date', 'eventName']),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ]);
        $this->noteOtherRow($link, 'ga4_events', $from, $to, $data);

        $rows = array_map(fn (array $row) => [
            'hotel_id' => $link->hotel_id,
            'date' => self::date((string) $row['dimensionValues'][0]['value']),
            'event_name' => mb_substr((string) $row['dimensionValues'][1]['value'], 0, 120),
            'event_count' => (int) $row['metricValues'][0]['value'],
            'event_value' => round((float) $row['metricValues'][1]['value'], 2),
        ], (array) ($data['rows'] ?? []));

        $next = $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;

        $this->store('ga4_events_daily', $rows, ['hotel_id', 'date', 'event_name'], ['event_count', 'event_value'], $link->hotel_id, $from, $to, $batch, $next === null);

        return $next;
    }

    private function ga4BookingDates(HotelGoogleLink $link, string $from, string $to, int $offset, ?string $batch): ?int
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
            // No event names: every event that carries the check-in parameter counts (search_dates, view_item, begin_checkout, purchase, ...).
            'dimensionFilter' => ['notExpression' => ['filter' => ['fieldName' => 'customEvent:'.$mapping['checkin'], 'stringFilter' => ['matchType' => 'EXACT', 'value' => '(not set)']]]],
            'orderBys' => self::orderBy($dimensions),
            'limit' => (string) $limit,
            'offset' => (string) $offset,
            'returnPropertyQuota' => true,
        ]);

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

        $next = $offset + $limit < (int) ($data['rowCount'] ?? 0) ? $offset + $limit : null;

        $this->store('ga4_booking_dates_daily', array_values($rows), ['hotel_id', 'row_key'], ['events'], $link->hotel_id, $from, $to, $batch, $next === null);

        return $next;
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
