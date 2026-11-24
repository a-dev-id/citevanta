<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The date range of a report: the last 28 days, 90 days or 12 months,
 * ending yesterday (Google data for today is incomplete), and the same
 * dates one year earlier for the comparison.
 */
final readonly class ReportPeriod
{
    public function __construct(
        public string $key,
        public Carbon $from,
        public Carbon $to,
    ) {}

    public static function make(?string $key): self
    {
        $ranges = (array) config('citevanta.reports.ranges');
        $key = isset($ranges[$key]) ? (string) $key : (string) array_key_first($ranges);
        $to = Carbon::yesterday();

        return new self($key, $to->copy()->subDays((int) $ranges[$key] - 1), $to);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function label(): string
    {
        return match ($this->key) {
            '12m' => 'Last 12 months',
            default => 'Last '.$this->days().' days',
        }.' ('.$this->from->format('j M Y').' to '.$this->to->format('j M Y').')';
    }

    public function previousYearFrom(): Carbon
    {
        return $this->from->copy()->subYear();
    }

    public function previousYearTo(): Carbon
    {
        return $this->to->copy()->subYear();
    }

    /**
     * The same period last year can be shown only when the synced data reaches back that far
     * (13 months after the first sync, so 28 and 90 days yes, 12 months no).
     */
    public function canCompare(int $hotelId): bool
    {
        $earliest = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->min('date');

        return $earliest !== null && Carbon::parse($earliest)->lte($this->previousYearFrom()->addDays(3));
    }

    /**
     * Size of one chart bucket in days: days for 28 days, weeks for 90 days, 30-day periods for a year.
     */
    public function bucketDays(): int
    {
        return match (true) {
            $this->days() <= 31 => 1,
            $this->days() <= 100 => 7,
            default => 30,
        };
    }
}
