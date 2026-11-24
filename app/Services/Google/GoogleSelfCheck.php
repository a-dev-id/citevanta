<?php

namespace App\Services\Google;

use App\Models\HotelGoogleLink;
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
        $data = $this->api->runReport((string) $link->ga4_property, [
            'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
            'metrics' => [['name' => 'sessions'], ['name' => 'ecommercePurchases']],
        ]);
        $values = array_map(fn ($v) => (int) ($v['value'] ?? 0), (array) ($data['rows'][0]['metricValues'] ?? []));
        $live = ['sessions' => $values[0] ?? 0, 'purchases' => $values[1] ?? 0];

        $stored = DB::table('ga4_traffic_daily')->where('hotel_id', $link->hotel_id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('coalesce(sum(sessions),0) as sessions, coalesce(sum(purchases),0) as purchases')->first();
        $stored = ['sessions' => (int) $stored->sessions, 'purchases' => (int) $stored->purchases];

        $result = [
            'status' => self::differs($stored['sessions'], $live['sessions']) || self::differs($stored['purchases'], $live['purchases']) ? 'mismatch' : 'ok',
            'checked_at' => now()->toIso8601String(), 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'live' => $live, 'stored' => $stored,
        ];

        $link->forceFill(['data_check' => $result])->save();

        return $result;
    }

    /** More than 2% away from the GA4 number (any stored number when GA4 says zero). */
    public static function differs(int $stored, int $live): bool
    {
        return abs($stored - $live) > self::TOLERANCE * $live;
    }

    /**
     * The warning to show, or null when the last check was fine (or has not run).
     */
    public static function warning(?HotelGoogleLink $link): ?string
    {
        $check = $link?->data_check;

        if (! is_array($check) || ($check['status'] ?? null) !== 'mismatch') {
            return null;
        }

        return sprintf(
            'Data may be incomplete. For %s to %s we stored %s sessions and %s purchases, but Google Analytics shows %s sessions and %s purchases.',
            Carbon::parse($check['from'])->format('j M'), Carbon::parse($check['to'])->format('j M Y'),
            number_format($check['stored']['sessions']), number_format($check['stored']['purchases']),
            number_format($check['live']['sessions']), number_format($check['live']['purchases']),
        );
    }
}
