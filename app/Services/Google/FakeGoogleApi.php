<?php

namespace App\Services\Google;

use App\Models\Hotel;
use App\Services\Support\Url;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Local development and tests (no GOOGLE_SERVICE_ACCOUNT_JSON_PATH): every hotel with a
 * website has a GA4 property and a Search Console domain property "shared"
 * with the agent, with deterministic numbers. ChatGPT and Perplexity send
 * some referral visits; purchases exist; the booking engine hostname shows
 * up for hotels that set one.
 */
final class FakeGoogleApi implements GoogleApi
{
    private const SOURCES = [
        ['google', 'organic'], ['(direct)', '(none)'], ['chatgpt.com', 'referral'], ['perplexity.ai', 'referral'], ['booking.com', 'referral'],
    ];

    private const COUNTRIES = ['Indonesia', 'Australia', 'Germany', 'United States'];

    private const PAGES = ['/', '/rooms', '/offers', '/spa'];

    private const DEVICES = ['mobile', 'desktop', 'tablet'];

    private const EVENTS = ['page_view', 'session_start', 'search_dates', 'view_item', 'begin_checkout', 'purchase'];

    /** Events that carry stay dates in the fake booking engine. */
    private const STAY_EVENTS = ['search_dates', 'begin_checkout', 'purchase'];

    public function properties(): array
    {
        return $this->hotels()->map(fn (Hotel $hotel) => [
            'property' => 'properties/'.(900000 + $hotel->id),
            'displayName' => $hotel->name.' - GA4',
            'account' => $hotel->name,
        ])->values()->all();
    }

    public function webStreamUrls(string $property): array
    {
        $hotel = $this->hotelFor($property);

        return $hotel ? [(string) $hotel->website] : [];
    }

    public function propertyDetails(string $property): array
    {
        return ['timeZone' => 'Asia/Makassar', 'currencyCode' => 'IDR'];
    }

    public function customDimensions(string $property): array
    {
        return [
            ['parameterName' => 'checkin_date', 'displayName' => 'Check-in date', 'scope' => 'EVENT'],
            ['parameterName' => 'checkout_date', 'displayName' => 'Check-out date', 'scope' => 'EVENT'],
            ['parameterName' => 'adults', 'displayName' => 'Adults', 'scope' => 'EVENT'],
            ['parameterName' => 'children', 'displayName' => 'Children', 'scope' => 'EVENT'],
        ];
    }

    public function runReport(string $property, array $body): array
    {
        $hotel = $this->hotelFor($property);
        $dimensions = array_map(fn ($d) => $d['name'], (array) ($body['dimensions'] ?? []));
        $metrics = array_map(fn ($m) => $m['name'], (array) ($body['metrics'] ?? []));
        $range = $body['dateRanges'][0] ?? ['startDate' => '7daysAgo', 'endDate' => 'today'];
        $days = in_array('date', $dimensions, true) ? $this->days($range['startDate'], $range['endDate']) : ['all'];
        $hosts = array_values(array_filter([Url::host((string) $hotel?->website), $hotel?->booking_engine_domain]));
        $eventFilter = $body['dimensionFilter']['filter']['stringFilter']['value'] ?? null;
        $eventList = $body['dimensionFilter']['filter']['inListFilter']['values'] ?? null;
        $hasCustom = count(array_filter($dimensions, fn ($name) => str_starts_with($name, 'customEvent:'))) > 0;

        $rows = [];
        foreach ($days as $d => $day) {
            foreach (in_array('sessionSource', $dimensions, true) ? self::SOURCES : [[null, null]] as $s => [$source, $medium]) {
                foreach (in_array('eventName', $dimensions, true) ? self::EVENTS : [null] as $event) {
                    foreach (in_array('hostName', $dimensions, true) ? ($hosts ?: ['example.com']) : [null] as $host) {
                        foreach ($hasCustom ? [0, 1, 2] : [0] as $variant) {
                            if ($eventFilter !== null && $event !== null && $event !== $eventFilter) {
                                continue;
                            }

                            if ($hasCustom && $event !== null && ! in_array($event, self::STAY_EVENTS, true)) {
                                continue;
                            }

                            if ($eventList !== null && $event !== null && ! in_array($event, $eventList, true)) {
                                continue;
                            }

                            $seed = crc32($property.$day.$source.$event.$host.$variant);
                            $values = [
                                'date' => $day === 'all' ? null : str_replace('-', '', $day),
                                'sessionSource' => $source, 'sessionMedium' => $medium,
                                'country' => self::COUNTRIES[($d + $s) % count(self::COUNTRIES)],
                                'landingPage' => self::PAGES[($d + $s) % count(self::PAGES)],
                                'deviceCategory' => self::DEVICES[$s % count(self::DEVICES)],
                                'eventName' => $event, 'hostName' => $host,
                            ];
                            foreach ($dimensions as $name) {
                                if (str_starts_with($name, 'customEvent:')) {
                                    $values[$name] = $this->customValue(substr($name, 12), $day, $seed);
                                }
                            }
                            $sessions = 3 + $seed % 40;
                            $purchases = match (true) {
                                $source === null && $event === null => 1 + $seed % 3,
                                $eventFilter === 'purchase' || $event === 'purchase' || $source === 'chatgpt.com' || $source === 'google' => $seed % 3,
                                default => 0,
                            };
                            $metricValues = [
                                'sessions' => $sessions, 'totalUsers' => $sessions - 1, 'activeUsers' => $sessions - 1,
                                'engagedSessions' => intdiv($sessions * 2, 3), 'keyEvents' => $purchases,
                                'ecommercePurchases' => $purchases, 'purchaseRevenue' => $purchases * 2500000,
                                'eventCount' => $sessions * 2, 'eventValue' => $purchases * 2500000,
                            ];

                            $rows[] = [
                                'dimensionValues' => array_map(fn ($name) => ['value' => (string) ($values[$name] ?? '(not set)')], $dimensions),
                                'metricValues' => array_map(fn ($name) => ['value' => (string) ($metricValues[$name] ?? 0)], $metrics),
                            ];
                        }
                    }
                }
            }
        }

        $offset = (int) ($body['offset'] ?? 0);
        $limit = (int) ($body['limit'] ?? 10000);

        return [
            'dimensionHeaders' => array_map(fn ($name) => ['name' => $name], $dimensions),
            'metricHeaders' => array_map(fn ($name) => ['name' => $name], $metrics),
            'rows' => array_slice($rows, $offset, $limit),
            'rowCount' => count($rows),
            'propertyQuota' => ['tokensPerHour' => ['consumed' => 5, 'remaining' => 39995]],
        ];
    }

    /**
     * Stay dates for a custom event dimension: check-in 1 to 12 weeks after the day, 1 to 6 nights.
     */
    private function customValue(string $parameter, string $day, int $seed): string
    {
        $field = array_search($parameter, GoogleHealth::detectMapping([$parameter]), true);
        $stay = 1 + $seed % 6;
        $checkin = Carbon::parse($day === 'all' ? 'today' : $day)->addDays(7 + intdiv($seed, 7) % 80);

        return match ($field) {
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkin->addDays($stay)->toDateString(),
            'nights' => (string) $stay,
            'adults' => (string) (1 + $seed % 3),
            'children' => (string) ($seed % 3),
            default => '(not set)',
        };
    }

    public function sites(): array
    {
        return $this->hotels()->map(fn (Hotel $hotel) => [
            'siteUrl' => 'sc-domain:'.Url::host((string) $hotel->website),
            'permissionLevel' => 'siteRestrictedUser',
        ])->values()->all();
    }

    public function searchAnalytics(string $siteUrl, array $body): array
    {
        $domain = str_replace('sc-domain:', '', $siteUrl);
        $hotel = $this->hotels()->first(fn (Hotel $h) => Url::host((string) $h->website) === $domain);
        $city = strtolower((string) ($hotel?->city ?? 'ubud'));
        $queries = [
            strtolower((string) $hotel?->name), "best resort in {$city}", "{$city} villa with private pool",
            "{$city} hotel christmas", "{$city} resort new year", "nyepi {$city} hotel", "family hotel {$city}",
            "honeymoon {$city}", "{$city} spa resort", "where to stay in {$city}",
        ];
        $rows = [];

        foreach ($queries as $q => $query) {
            $seed = crc32($siteUrl.$body['startDate'].$query);
            $impressions = 20 + $seed % 400;
            $clicks = intdiv($impressions * ($q < 2 ? 20 : 2), 100);
            $rows[] = [
                'keys' => [$query, 'https://'.$domain.self::PAGES[$q % count(self::PAGES)], ['idn', 'aus', 'deu', 'usa'][$q % 4], ['MOBILE', 'DESKTOP'][$q % 2]],
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
                'position' => 1 + ($seed % 150) / 10,
            ];
        }

        return ['rows' => array_slice($rows, (int) ($body['startRow'] ?? 0), (int) ($body['rowLimit'] ?? 1000))];
    }

    /** @return Collection<int, Hotel> */
    private function hotels()
    {
        return Hotel::query()->where('is_audit', false)->whereNotNull('website')->orderBy('id')->get();
    }

    private function hotelFor(string $property): ?Hotel
    {
        return Hotel::query()->find(((int) str_replace('properties/', '', $property)) - 900000);
    }

    /**
     * @return list<string>
     */
    private function days(string $start, string $end): array
    {
        $to = $this->date($end);
        // Fake data covers the most recent 120 days of any range, so recent reports are never empty.
        $from = $this->date($start)->max($to->copy()->subDays(119));
        $days = [];

        for ($day = $from->copy(); $day->lte($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    private function date(string $value): Carbon
    {
        return match (true) {
            $value === 'today' => Carbon::today(),
            $value === 'yesterday' => Carbon::yesterday(),
            (bool) preg_match('/^(\d+)daysAgo$/', $value, $m) => Carbon::today()->subDays((int) $m[1]),
            default => Carbon::parse($value)->startOfDay(),
        };
    }
}
