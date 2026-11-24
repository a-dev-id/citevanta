<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The date range of a report and what it is compared with.
 *
 * Presets (last 7, 28, 30, 90 days, this month, last month, last 12 months, this year) and a custom range.
 * A range never starts before the first synced day, and a custom range never ends after the last fully synced
 * day. The comparison is nothing, the previous period (same length, directly before) or the same period last
 * year (optionally shifted by 52 weeks so the weekdays match). A comparison that would start before the first
 * synced day is not offered: its reason ("data starts ...") is kept in $unavailable. Days that were never
 * synced are not zero, so they are never used.
 */
final readonly class ReportPeriod
{
    public const PRESETS = [
        '7d' => 'Last 7 days',
        '28d' => 'Last 28 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        '12m' => 'Last 12 months',
        'this_year' => 'This year',
    ];

    public const COMPARE = ['none' => 'No comparison', 'previous' => 'Previous period', 'year' => 'Same period last year'];

    /**
     * @param  array<string, string>  $unavailable  comparison => why it cannot be used
     */
    public function __construct(
        public string $key,
        public Carbon $from,
        public Carbon $to,
        public string $compare = 'none',
        public bool $matchWeekday = false,
        public ?Carbon $compareFrom = null,
        public ?Carbon $compareTo = null,
        public ?Carbon $earliest = null,
        public ?Carbon $latest = null,
        public array $unavailable = [],
    ) {}

    /**
     * A preset without a comparison (and without knowing the synced bounds).
     */
    public static function make(?string $key): self
    {
        return self::resolve(['range' => $key, 'compare' => 'none']);
    }

    /**
     * @param  array<string, mixed>  $input  range (a preset key), from, to (Y-m-d), compare (none|previous|year), dow (1)
     */
    public static function resolve(array $input, ?Carbon $earliest = null, ?Carbon $latest = null): self
    {
        $yesterday = Carbon::yesterday();
        $earliest = $earliest?->copy()->startOfDay();
        $latest = $latest?->copy()->startOfDay();
        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null);

        if ($from !== null && $to !== null) {
            $key = 'custom';

            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }

            $to = $to->min($latest ?? $yesterday);
            $from = $from->min($to);
        } else {
            $key = array_key_exists((string) ($input['range'] ?? ''), self::PRESETS) ? (string) $input['range'] : '28d';
            [$from, $to] = self::presetRange($key);
        }

        if ($earliest !== null) {
            $from = $from->max($earliest);
            $to = $to->max($from);
        }

        $matchWeekday = ! empty($input['dow']) && (string) $input['dow'] !== '0';
        $requested = array_key_exists((string) ($input['compare'] ?? ''), self::COMPARE) ? (string) $input['compare'] : 'previous';
        $unavailable = [];
        $ranges = [];

        foreach (['previous', 'year'] as $mode) {
            $ranges[$mode] = self::compareRange($mode, $from, $to, $matchWeekday);

            if ($earliest !== null && $ranges[$mode][0]->lt($earliest)) {
                $unavailable[$mode] = 'data starts '.$earliest->format('j M Y');
            }
        }

        $compare = isset($ranges[$requested]) && ! isset($unavailable[$requested]) ? $requested : 'none';
        [$compareFrom, $compareTo] = $compare === 'none' ? [null, null] : $ranges[$compare];

        return new self($key, $from, $to, $compare, $matchWeekday, $compareFrom, $compareTo, $earliest, $latest, $unavailable);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function presetRange(string $key): array
    {
        $yesterday = Carbon::yesterday();
        $lastMonth = Carbon::today()->subMonthNoOverflow();

        return match ($key) {
            'this_month' => [$yesterday->copy()->startOfMonth(), $yesterday],
            'last_month' => [$lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth()->startOfDay()],
            'this_year' => [$yesterday->copy()->startOfYear(), $yesterday],
            default => [$yesterday->copy()->subDays((int) (config('citevanta.reports.ranges')[$key] ?? 28) - 1), $yesterday],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function compareRange(string $mode, Carbon $from, Carbon $to, bool $matchWeekday = false): array
    {
        $days = (int) $from->diffInDays($to);

        if ($mode === 'year') {
            $start = $matchWeekday ? $from->copy()->subDays(364) : $from->copy()->subYear();

            return [$start, $start->copy()->addDays($days)];
        }

        $end = $from->copy()->subDay();

        return [$end->copy()->subDays($days), $end];
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function hasComparison(): bool
    {
        return $this->compare !== 'none' && $this->compareFrom !== null;
    }

    public function label(): string
    {
        $name = $this->key === 'custom' ? 'Custom range' : self::PRESETS[$this->key];

        return $name.' ('.$this->from->format('j M Y').' to '.$this->to->format('j M Y').')';
    }

    /** "Previous period" or "Same period last year" for the chosen comparison. */
    public function compareName(): string
    {
        return strtolower(self::COMPARE[$this->compare]).($this->compare === 'year' && $this->matchWeekday ? ' (same weekdays)' : '');
    }

    /** "vs previous period" for KPI cards. */
    public function compareShortName(): string
    {
        return $this->compare === 'year' ? 'vs same period last year' : 'vs previous period';
    }

    public function compareLabel(): ?string
    {
        return $this->hasComparison() ? $this->compareFrom->format('j M Y').' to '.$this->compareTo->format('j M Y') : null;
    }

    /** The preset that has exactly this range, if any. */
    public function matchedPreset(): ?string
    {
        foreach (array_keys(self::PRESETS) as $key) {
            [$from, $to] = self::presetRange($key);

            if ($this->earliest !== null) {
                $from = $from->max($this->earliest);
            }

            if ($from->eq($this->from) && $to->eq($this->to)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * URL parameters that keep this selection (a link to another tab, table page or the CSV).
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $query = ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'compare' => $this->compare];

        if ($this->matchWeekday) {
            $query['dow'] = '1';
        }

        return $query;
    }

    /**
     * Size of one chart bucket in days: days for up to 31 days, weeks up to 100 days, 30-day periods beyond.
     */
    public function bucketDays(): int
    {
        return match (true) {
            $this->days() <= 31 => 1,
            $this->days() <= 100 => 7,
            default => 30,
        };
    }

    /** The first day with stored GA4 traffic (null when nothing is stored). */
    public static function firstGa4Day(int $hotelId): ?Carbon
    {
        $first = DB::table('ga4_traffic_daily')->where('hotel_id', $hotelId)->min('date');

        return $first === null ? null : Carbon::parse($first);
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
