<?php

namespace App\Services\Google;

use App\Models\HotelGoogleLink;
use App\Services\Support\Url;
use Illuminate\Support\Facades\DB;

/**
 * Tracking health check (after linking and weekly). One API call per check:
 *
 *  - receiving: GA4 has sessions in the last 7 days
 *  - purchases: purchase events in the last 30 days, with value and currency
 *  - cross_domain: the booking engine's hostname appears in GA4 (hostName)
 *  - ai_referrals: visits from AI assistants in the last 30 days
 *  - booking_dates: event-scoped custom dimensions for check-in, check-out,
 *    adults, children, nights (auto-detected; the owner confirms the mapping)
 *  - search_console: the Search Console site has impressions in the last 28 days
 *
 * Each result: green, amber or red, a one-line explanation, and a text for
 * the hotel's web developer.
 */
final class GoogleHealth
{
    public const CHECKS = ['receiving', 'purchases', 'cross_domain', 'ai_referrals', 'booking_dates', 'search_console'];

    public const LABELS = [
        'receiving' => 'GA4 is receiving data',
        'purchases' => 'Bookings are tracked as purchases',
        'cross_domain' => 'Booking engine is tracked (cross-domain)',
        'ai_referrals' => 'Visits from AI assistants',
        'booking_dates' => 'Booking dates and guests',
        'search_console' => 'Search Console data',
    ];

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * Run one check and store its result on the link.
     *
     * @return array{status: string, message: string, developer: ?string, details: array<string, mixed>, checked_at: string}
     */
    public function run(HotelGoogleLink $link, string $check): array
    {
        try {
            $result = match ($check) {
                'receiving' => $this->receiving($link),
                'purchases' => $this->purchases($link),
                'cross_domain' => $this->crossDomain($link),
                'ai_referrals' => $this->aiReferrals($link),
                'booking_dates' => $this->bookingDates($link),
                'search_console' => $this->searchConsole($link),
            };
        } catch (GoogleApiException $e) {
            if ($e->isQuota()) {
                throw $e;
            }

            $result = self::result('red', $e->isPermission()
                ? 'We lost access. Check that '.app(GoogleAgent::class)->email().' still has access in GA4 and Search Console.'
                : 'Google answered with an error: '.$e->getMessage());
        }

        $result['checked_at'] = now()->toIso8601String();
        $this->store($link, $check, $result);

        return $result;
    }

    private function receiving(HotelGoogleLink $link): array
    {
        if ($link->ga4_property === null) {
            return self::result('red', 'No GA4 property is linked.');
        }

        $sessions = $this->total($link, '7daysAgo', ['sessions'])['sessions'];

        return $sessions > 0
            ? self::result('green', number_format($sessions).' sessions in the last 7 days.', details: ['sessions' => $sessions])
            : self::result('red', 'GA4 received no visits in the last 7 days.', $this->developer('receiving', $link));
    }

    private function purchases(HotelGoogleLink $link): array
    {
        if ($link->ga4_property === null) {
            return self::result('red', 'No GA4 property is linked.');
        }

        $totals = $this->total($link, '30daysAgo', ['ecommercePurchases', 'purchaseRevenue']);
        $currency = $link->ga4_currency ?? '';
        $details = ['purchases' => $totals['ecommercePurchases'], 'revenue' => $totals['purchaseRevenue'], 'currency' => $currency];

        return match (true) {
            $totals['ecommercePurchases'] > 0 && $totals['purchaseRevenue'] > 0 => self::result('green',
                sprintf('%d bookings worth %s %s in the last 30 days.', $totals['ecommercePurchases'], $currency, number_format($totals['purchaseRevenue'], 0)), details: $details),
            $totals['ecommercePurchases'] > 0 => self::result('amber',
                $totals['ecommercePurchases'].' purchase events in the last 30 days, but without a booking value.', $this->developer('purchases_value', $link), $details),
            default => self::result('red', 'No purchase events in the last 30 days, so bookings cannot be measured.', $this->developer('purchases', $link), $details),
        };
    }

    private function crossDomain(HotelGoogleLink $link): array
    {
        $booking = Url::host((string) $link->hotel->booking_engine_domain);

        if ($link->ga4_property === null) {
            return self::result('red', 'No GA4 property is linked.');
        }

        if ($booking === '') {
            return self::result('amber', 'Add your booking engine domain below, so we can check that bookings are tracked.');
        }

        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'hostName']],
            'metrics' => [['name' => 'sessions']],
            'limit' => '50',
        ]);
        $hosts = array_map(fn ($row) => Url::host((string) $row['dimensionValues'][0]['value']), (array) ($data['rows'] ?? []));
        $found = collect($hosts)->contains(fn ($host) => Url::sameSite($host, $booking));

        return $found
            ? self::result('green', "Visits on {$booking} are tracked in the same GA4 property.", details: ['hosts' => $hosts])
            : self::result('red', "{$booking} does not appear in GA4, so bookings and their source are lost.", $this->developer('cross_domain', $link), ['hosts' => $hosts]);
    }

    private function aiReferrals(HotelGoogleLink $link): array
    {
        if ($link->ga4_property === null) {
            return self::result('red', 'No GA4 property is linked.');
        }

        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'sessionSource']],
            'metrics' => [['name' => 'sessions']],
            'limit' => '1000',
        ]);

        $platforms = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            if (($platform = AiSources::platform((string) $row['dimensionValues'][0]['value'])) !== null) {
                $platforms[$platform] = ($platforms[$platform] ?? 0) + (int) $row['metricValues'][0]['value'];
            }
        }
        arsort($platforms);

        return $platforms !== []
            ? self::result('green', array_sum($platforms).' visits from AI assistants in the last 30 days ('.implode(', ', array_map(fn ($p, $n) => "{$p} {$n}", array_keys($platforms), $platforms)).').', details: ['platforms' => $platforms])
            : self::result('amber', 'No visits from AI assistants in the last 30 days. This can be normal for now.', $this->developer('ai_referrals', $link));
    }

    private function bookingDates(HotelGoogleLink $link): array
    {
        if ($link->ga4_property === null) {
            return self::result('red', 'No GA4 property is linked.');
        }

        $dimensions = array_values(array_filter($this->api->customDimensions((string) $link->ga4_property), fn ($d) => strtoupper($d['scope']) === 'EVENT'));
        $names = array_column($dimensions, 'parameterName');
        $suggested = self::detectMapping($names);

        $mapping = $link->date_mapping ?? [];
        if (! ($mapping['confirmed'] ?? false)) {
            $link->forceFill(['date_mapping' => $suggested + ['confirmed' => false]])->save();
            $mapping = $suggested;
        }

        $details = ['parameters' => $names, 'suggested' => $suggested];

        return match (true) {
            ($mapping['checkin'] ?? null) && ($mapping['checkout'] ?? null) => self::result('green',
                'Check-in and check-out dates are available'.(($link->date_mapping['confirmed'] ?? false) ? '.' : '. Please confirm the mapping below.'), details: $details),
            ($mapping['checkin'] ?? null) || ($mapping['checkout'] ?? null) || ($mapping['nights'] ?? null) => self::result('amber',
                'Only part of the booking dates is available.', $this->developer('booking_dates', $link), $details),
            default => self::result('red', 'GA4 has no custom dimensions for booking dates, so we cannot show which dates guests search for.', $this->developer('booking_dates', $link), $details),
        };
    }

    private function searchConsole(HotelGoogleLink $link): array
    {
        if ($link->gsc_site === null) {
            return self::result('red', 'No Search Console site is linked.');
        }

        $data = $this->api->searchAnalytics($link->gsc_site, [
            'startDate' => now()->subDays(30)->toDateString(),
            'endDate' => now()->subDays(3)->toDateString(),
            'type' => 'web',
            'rowLimit' => 1,
        ]);
        $impressions = (int) ($data['rows'][0]['impressions'] ?? 0);

        return $impressions > 0
            ? self::result('green', number_format($impressions).' Google Search impressions in the last 4 weeks.', details: ['impressions' => $impressions])
            : self::result('amber', 'Search Console has no impressions for this site in the last 4 weeks.');
    }

    /**
     * The best match among the property's event parameters, per field.
     *
     * @param  list<string>  $parameterNames
     * @return array<string, string|null>
     */
    public static function detectMapping(array $parameterNames): array
    {
        $lower = array_combine(array_map('strtolower', $parameterNames), $parameterNames) ?: [];
        $mapping = [];

        foreach ((array) config('citevanta.booking_date_parameters', []) as $field => $candidates) {
            $mapping[$field] = null;

            foreach ($candidates as $candidate) {
                if (isset($lower[$candidate])) {
                    $mapping[$field] = $lower[$candidate];

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $metrics
     * @return array<string, float>
     */
    private function total(HotelGoogleLink $link, string $from, array $metrics): array
    {
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from, 'endDate' => 'today']],
            'metrics' => array_map(fn ($name) => ['name' => $name], $metrics),
        ]);

        $values = [];
        foreach ($metrics as $i => $metric) {
            $values[$metric] = collect((array) ($data['rows'] ?? []))->sum(fn ($row) => (float) ($row['metricValues'][$i]['value'] ?? 0));
        }

        return $values;
    }

    private function store(HotelGoogleLink $link, string $check, array $result): void
    {
        DB::transaction(function () use ($link, $check, $result): void {
            $fresh = HotelGoogleLink::query()->lockForUpdate()->find($link->id);
            $health = (array) ($fresh->health ?? []);
            $health[$check] = $result;
            $fresh->forceFill(['health' => $health, 'health_checked_at' => now()])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{status: string, message: string, developer: ?string, details: array<string, mixed>}
     */
    private static function result(string $status, string $message, ?string $developer = null, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'developer' => $developer, 'details' => $details];
    }

    /**
     * "Send to my web developer": what to fix, ready to copy.
     */
    private function developer(string $issue, HotelGoogleLink $link): string
    {
        $site = Url::host((string) $link->hotel->website);
        $booking = Url::host((string) $link->hotel->booking_engine_domain) ?: 'the booking engine';
        $property = $link->ga4_property_name ?? $link->ga4_property;

        return match ($issue) {
            'receiving' => "Hi, could you check our Google Analytics 4 tracking? The GA4 property \"{$property}\" received no visits in the last 7 days. Please make sure the GA4 tag (the G-... measurement ID of this property) is installed on every page of {$site} and fires on page load, for example with Google Tag Manager or the gtag.js snippet. Thank you!",
            'purchases' => "Hi, could you send bookings to Google Analytics 4 as \"purchase\" events? On the booking confirmation page of {$booking}, please send the GA4 recommended e-commerce event \"purchase\" with transaction_id, value (the booking total) and currency (for example IDR). Most booking engines have a GA4 or Google Tag Manager integration for this. Thank you!",
            'purchases_value' => "Hi, our GA4 \"purchase\" events arrive without a value. Please add the parameters value (the booking total, a number) and currency (for example IDR) to the purchase event on the booking confirmation page of {$booking}. Thank you!",
            'cross_domain' => "Hi, could you set up GA4 cross-domain measurement between {$site} and {$booking}? In GA4: Admin > Data streams > our web stream > Configure tag settings > Configure your domains, add both {$site} and {$booking}. The same GA4 measurement ID must also be installed on the booking engine pages, so a booking keeps its original source (for example ChatGPT). Thank you!",
            'ai_referrals' => 'Hi, we want to measure visits from AI assistants like ChatGPT and Perplexity in GA4. Please make sure our website does not hide the referrer (no "Referrer-Policy: no-referrer" header or meta tag; "strict-origin-when-cross-origin" is fine) and that links in our profiles use utm_source when we share them. Thank you!',
            default => "Hi, could you send the stay dates to Google Analytics 4? On the booking engine ({$booking}), please add these event parameters to the search_dates event (the one sent when a guest searches for dates), and to begin_checkout and purchase if you have them: check_in_date and check_out_date (format YYYY-MM-DD), adults and children (numbers), and nights. Then in GA4 go to Admin > Data display > Custom definitions > Create custom dimension and create one per parameter with scope \"Event\": Dimension name \"Check-in date\" with Event parameter \"check_in_date\", \"Check-out date\" with \"check_out_date\", \"Adults\" with \"adults\", \"Children\" with \"children\", \"Nights\" with \"nights\". Thank you!",
        };
    }
}
