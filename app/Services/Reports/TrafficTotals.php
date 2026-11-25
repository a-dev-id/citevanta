<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * "All sessions", "all purchases" and "all revenue" of a hotel for a range of days.
 *
 * They come from the daily totals (a GA4 request with only the date dimension, table ga4_totals_daily), because
 * adding up the detailed rows (source, country, landing page, device) can give more sessions than GA4 reports.
 * Only for days that have no daily total yet (right after an upgrade, before the totals are copied) the detailed
 * rows of that day are used, so a report is never empty.
 */
final class TrafficTotals
{
    /**
     * @return array{sessions: int, purchases: int, revenue: float}
     */
    public static function sum(int $hotelId, string $from, string $to): array
    {
        $select = 'coalesce(sum(sessions),0) as sessions, coalesce(sum(purchases),0) as purchases, coalesce(sum(revenue),0) as revenue';

        $totals = DB::table('ga4_totals_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$from, $to])->selectRaw($select)->first();
        $detail = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$from, $to])
            ->whereNotIn('date', fn ($query) => $query->select('date')->from('ga4_totals_daily')->where('hotel_id', $hotelId))
            ->selectRaw($select)->first();

        return [
            'sessions' => (int) $totals->sessions + (int) $detail->sessions,
            'purchases' => (int) $totals->purchases + (int) $detail->purchases,
            'revenue' => round((float) $totals->revenue + (float) $detail->revenue, 2),
        ];
    }

    /** Whether every day of the range has a daily total (false: some days still use the detailed rows). */
    public static function isComplete(int $hotelId, string $from, string $to): bool
    {
        $days = (int) (strtotime($to) - strtotime($from)) / 86400 + 1;

        return DB::table('ga4_totals_daily')->where('hotel_id', $hotelId)->whereBetween('date', [$from, $to])->count() >= $days;
    }
}
