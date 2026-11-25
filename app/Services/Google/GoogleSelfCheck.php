<?php

namespace App\Services\Google;

use App\Models\HotelGoogleLink;
use App\Services\Reports\TrafficTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * After a sync: compare what we stored for the last 28 days (sessions and purchases) with one small
 * live GA4 request. When they differ by more than 2% the reports and Admin > Integrations > Google
 * say "data may be incomplete" instead of silently showing the numbers.
 */
final class GoogleSelfCheck
{
    public const DAYS = 28;

    /** Largest accepted difference, as a share of the GA4 number. */
    public const TOLERANCE = 0.02;

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * One GA4 request. Skipped (null) for fake data and links without a GA4 property.
     *
     * @return array<string, mixed>|null
     *
     * @throws GoogleApiException
     */
    public function run(HotelGoogleLink $link): ?array
    {
        if ($link->ga4_property === null || config('services.google.fake')) {
            return null;
        }

        $to = Carbon::yesterday();
        $from = $to->copy()->subDays(self::DAYS - 1);
        $range = [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]];

        // All traffic: a request with no dimensions.
        $data = $this->api->runReport((string) $link->ga4_property, ['dateRanges' => $range, 'metrics' => [['name' => 'sessions'], ['name' => 'ecommercePurchases']]]);
        $values = array_map(fn ($v) => (int) ($v['value'] ?? 0), (array) ($data['rows'][0]['metricValues'] ?? []));
        $live = ['sessions' => $values[0] ?? 0, 'purchases' => $values[1] ?? 0];

        // AI traffic: the sessions of the AI sources.
        $bySource = $this->api->runReport((string) $link->ga4_property, ['dateRanges' => $range, 'dimensions' => [['name' => 'sessionSource']], 'metrics' => [['name' => 'sessions'], ['name' => 'ecommercePurchases']], 'limit' => '10000']);
        $aiLive = ['sessions' => 0, 'purchases' => 0];
        foreach ((array) ($bySource['rows'] ?? []) as $row) {
            if (AiSources::platform((string) ($row['dimensionValues'][0]['value'] ?? '')) !== null) {
                $aiLive['sessions'] += (int) ($row['metricValues'][0]['value'] ?? 0);
                $aiLive['purchases'] += (int) ($row['metricValues'][1]['value'] ?? 0);
            }
        }

        $all = TrafficTotals::sum($link->hotel_id, $from->toDateString(), $to->toDateString());
        $stored = ['sessions' => $all['sessions'], 'purchases' => $all['purchases']];
        $sources = DB::table('ga4_traffic_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->distinct()->pluck('source')
            ->filter(fn ($name) => AiSources::platform((string) $name) !== null)->all();
        $aiRow = $sources === [] ? null : DB::table('ga4_traffic_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('source', $sources)->selectRaw('coalesce(sum(sessions),0) as sessions, coalesce(sum(purchases),0) as purchases')->first();
        $aiStored = ['sessions' => (int) ($aiRow->sessions ?? 0), 'purchases' => (int) ($aiRow->purchases ?? 0)];

        $differs = self::differs($stored['sessions'], $live['sessions']) || self::differs($stored['purchases'], $live['purchases'])
            || self::differs($aiStored['sessions'], $aiLive['sessions']) || self::differs($aiStored['purchases'], $aiLive['purchases']);

        $result = [
            'status' => $differs ? 'mismatch' : 'ok',
            'checked_at' => now()->toIso8601String(), 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'live' => $live, 'stored' => $stored, 'ai_live' => $aiLive, 'ai_stored' => $aiStored,
        ];

        $link->forceFill(['data_check' => $result])->save();

        return $result;
    }

    /** More than 2% away from the GA4 number (any stored number when GA4 says zero). */
    public static function differs(int $stored, int $live): bool
    {
        return abs($stored - $live) > self::TOLERANCE * $live;
    }

    /** What a hotel user sees: no numbers, nothing to act on. */
    public const HOTEL_MESSAGE = 'Some totals are being checked and may change.';

    /**
     * The warning for the person looking: the full numbers (stored against GA4) for admins, a short message for hotel users.
     */
    public static function warningFor(?HotelGoogleLink $link, bool $admin): ?string
    {
        $detail = self::warning($link);

        return $detail === null ? null : ($admin ? $detail : self::HOTEL_MESSAGE);
    }

    /**
     * The full warning with the numbers (for admins), or null when the last check was fine (or has not run).
     */
    public static function warning(?HotelGoogleLink $link): ?string
    {
        $check = $link?->data_check;

        if (! is_array($check) || ($check['status'] ?? null) !== 'mismatch') {
            return null;
        }

        $text = sprintf(
            'Data may be incomplete. For %s to %s we stored %s sessions and %s purchases (all traffic), but Google Analytics shows %s sessions and %s purchases.',
            Carbon::parse($check['from'])->format('j M'), Carbon::parse($check['to'])->format('j M Y'),
            number_format($check['stored']['sessions']), number_format($check['stored']['purchases']),
            number_format($check['live']['sessions']), number_format($check['live']['purchases']),
        );

        if (isset($check['ai_live'], $check['ai_stored']) && (self::differs($check['ai_stored']['sessions'], $check['ai_live']['sessions']) || self::differs($check['ai_stored']['purchases'], $check['ai_live']['purchases']))) {
            $text .= sprintf(' AI traffic: stored %s sessions and %s purchases, GA4 shows %s and %s.', number_format($check['ai_stored']['sessions']), number_format($check['ai_stored']['purchases']), number_format($check['ai_live']['sessions']), number_format($check['ai_live']['purchases']));
        }

        return $text;
    }
}
