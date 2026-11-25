<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Searches by check-in date and by length of stay, from the stay dates GA4 receives
 * with guests' searches in the booking engine (the search_dates event) and with
 * begin_checkout (started) and purchase (booked). No event name is filtered: every
 * event that carries the stay dates counts, and everything that is not a started
 * or finished booking counts as a search. Event counts, not people. Only the events that
 * happened in the report period are used, so a date's demand is what guests
 * looked for during that time. Empty until the fields are mapped.
 *
 * Optionally for one country (the guest's country as GA4 returns it): the calendar, the stay tables and the CSV
 * are then only about that country. "unknown" is the empty country (rows synced before countries were read, and
 * GA4's "(not set)").
 */
final class BookingDemandReport
{
    public const TOP_DATES = 15;

    public const LONGEST_STAY = 10;

    public const HISTOGRAM_MAX = 15;

    private const COLUMNS = ['view_item' => 'looked', 'begin_checkout' => 'started', 'purchase' => 'booked'];

    /** How an empty country is shown. */
    public const UNKNOWN = 'Unknown';

    /** The value of the country filter for the empty country. */
    public const UNKNOWN_FILTER = 'unknown';

    /** Countries listed in a calendar tooltip before "+N more". */
    public const TOOLTIP_COUNTRIES = 3;

    public function __construct(private readonly Hotel $hotel, private readonly ReportPeriod $period, private readonly ?string $country = null) {}

    /** The country as one group: an empty country and GA4's "(not set)" are both "unknown" (''). */
    private static function countryGroup(): string
    {
        return "case when country in ('', '(not set)') then '' else country end";
    }

    /**
     * The countries guests searched from in this period, most searches first, for the report's Country dropdown.
     *
     * @return list<array{value: string, label: string, events: int}>
     */
    public function countryOptions(): array
    {
        $rows = $this->events(null, null, false)->where('event_name', '!=', 'purchase')->groupByRaw(self::countryGroup())
            ->selectRaw(self::countryGroup().' as name, sum(events) as events')->get();

        return $rows->map(fn ($row) => ['value' => $row->name === '' ? self::UNKNOWN_FILTER : (string) $row->name, 'label' => $row->name === '' ? self::UNKNOWN : (string) $row->name, 'events' => (int) $row->events])
            ->sort(fn ($a, $b) => [$b['events'], $a['label']] <=> [$a['events'], $b['label']])->values()->all();
    }

    /**
     * Per check-in date, oldest first, only dates from today on.
     *
     * @return list<array<string, scalar|null>>
     */
    public function byCheckin(?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->events($from, $to)->where('checkin', '>=', now()->toDateString())->groupBy('checkin', 'event_name')
            ->selectRaw('checkin, event_name, sum(events) as events')->get();
        $dates = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->checkin)->toDateString();
            $dates[$date] ??= ['date' => $date, 'looked' => 0, 'started' => 0, 'booked' => 0];
            $dates[$date][self::COLUMNS[$row->event_name] ?? 'looked'] += (int) $row->events;
        }

        ksort($dates);

        return array_values(array_map(fn (array $row) => $row + ['demand' => $row['looked'] + $row['started']], $dates));
    }

    /**
     * The dates with the most interest (searched + started), most first.
     *
     * @return list<array<string, scalar|null>>
     */
    public function topDates(): array
    {
        $rows = $this->byCheckin();
        usort($rows, fn ($a, $b) => [$b['demand'], $a['date']] <=> [$a['demand'], $b['date']]);

        return array_slice($rows, 0, self::TOP_DATES);
    }

    /**
     * Data for the check-in calendar: for every date from today on, what guests searched for (l), started
     * booking (s) and booked (b), the demand (d = l + s), and the stay lengths behind it ([nights, l, s, b],
     * most asked first; nights 0 = not known), and the top countries by searches (c: [name, count], m: how many more; the counts add up to l). Short keys,
     * because this goes to the browser as JSON.
     *
     * @return array{days: array<string, array<string, mixed>>, max: int, top: array{date: string, demand: int}|null, today: string}
     */
    public function calendar(): array
    {
        $rows = $this->events()->where('checkin', '>=', now()->toDateString())->groupByRaw('checkin, nights, event_name, '.self::countryGroup())
            ->selectRaw('checkin, nights, event_name, '.self::countryGroup().' as country_name, sum(events) as events')->get();
        $days = [];
        $countries = [];
        $column = ['view_item' => 'l', 'begin_checkout' => 's', 'purchase' => 'b'];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->checkin)->toDateString();
            $key = $column[$row->event_name] ?? 'l';
            $nights = (int) $row->nights;

            $days[$date] ??= ['l' => 0, 's' => 0, 'b' => 0, 'd' => 0, 'stays' => []];
            $days[$date]['stays'][$nights] ??= ['l' => 0, 's' => 0, 'b' => 0];
            $days[$date][$key] += (int) $row->events;
            $days[$date]['stays'][$nights][$key] += (int) $row->events;

            // Where the searches come from: searches per country, so the lines add up to the "searched" number.
            if ($key === 'l') {
                $name = $row->country_name === '' ? self::UNKNOWN : (string) $row->country_name;
                $countries[$date][$name] = ($countries[$date][$name] ?? 0) + (int) $row->events;
            }
        }

        $max = 0;
        $top = null;

        foreach ($days as $date => &$day) {
            $day['d'] = $day['l'] + $day['s'];
            $stays = [];
            foreach ($day['stays'] as $nights => $counts) {
                $stays[] = [$nights, $counts['l'], $counts['s'], $counts['b']];
            }
            usort($stays, fn ($x, $y) => ($y[1] + $y[2]) <=> ($x[1] + $x[2]));
            $day['stays'] = $stays;

            // "c": the top countries as [name, count], "m": how many more there are ("+2 more").
            $ranked = $countries[$date] ?? [];
            uksort($ranked, fn ($a, $b) => [$ranked[$b], $a] <=> [$ranked[$a], $b]);
            $day['c'] = array_map(fn ($name, $count) => [$name, $count], array_slice(array_keys($ranked), 0, self::TOOLTIP_COUNTRIES), array_slice(array_values($ranked), 0, self::TOOLTIP_COUNTRIES));
            $day['m'] = max(count($ranked) - self::TOOLTIP_COUNTRIES, 0);

            $max = max($max, $day['d']);
            if ($top === null || $day['d'] > $top['demand'] || ($day['d'] === $top['demand'] && $date < $top['date'])) {
                $top = ['date' => $date, 'demand' => $day['d']];
            }
        }
        unset($day);

        ksort($days);

        return ['days' => $days, 'max' => $max, 'top' => $top, 'today' => now()->toDateString()];
    }

    /**
     * How long guests want to stay: the share of searches and started bookings per number of nights
     * (1 to 14, then 15 or more). Null when no stay length is known.
     *
     * @return array{bars: list<array{n: int, label: string, demand: int, share: float}>, total: int, common: int, short_share: float}|null
     */
    public function stayHistogram(): ?array
    {
        $rows = $this->events()->where('event_name', '!=', 'purchase')->where('nights', '>=', 1)
            ->groupBy('nights')->selectRaw('nights, sum(events) as events')->get();
        $demand = array_fill(1, self::HISTOGRAM_MAX, 0);

        foreach ($rows as $row) {
            $demand[min((int) $row->nights, self::HISTOGRAM_MAX)] += (int) $row->events;
        }

        $total = array_sum($demand);

        if ($total === 0) {
            return null;
        }

        $bars = [];
        foreach ($demand as $n => $count) {
            $bars[] = ['n' => $n, 'label' => $n === self::HISTOGRAM_MAX ? $n.'+' : (string) $n, 'demand' => $count, 'share' => $count / $total];
        }

        return ['bars' => $bars, 'total' => $total, 'common' => (int) array_search(max($demand), $demand, true), 'short_share' => array_sum(array_slice($demand, 0, 3)) / $total];
    }

    /**
     * Per number of nights (the longest group is "10 or more").
     *
     * @return list<array<string, scalar|null>>
     */
    public function byStay(): array
    {
        $rows = $this->events()->whereNotNull('nights')->groupBy('nights', 'event_name')->selectRaw('nights, event_name, sum(events) as events')->get();
        $stays = [];

        foreach ($rows as $row) {
            $nights = min((int) $row->nights, self::LONGEST_STAY);
            $stays[$nights] ??= ['name' => $nights >= self::LONGEST_STAY ? self::LONGEST_STAY.' or more nights' : $nights.' '.($nights === 1 ? 'night' : 'nights'), 'nights' => $nights, 'looked' => 0, 'started' => 0, 'booked' => 0];
            $stays[$nights][self::COLUMNS[$row->event_name] ?? 'looked'] += (int) $row->events;
        }

        ksort($stays);

        return array_values($stays);
    }

    /**
     * @return array<string, ReportTable>
     */
    public function tables(?TableOptions $options = null): array
    {
        $options ??= new TableOptions;
        $compare = $this->period->hasComparison();
        $note = $compare ? ' Compared with the same check-in dates searched during '.$this->period->compareLabel().' ('.$this->period->compareName().').' : '';
        $columns = ['looked' => 'Searched|int', 'started' => 'Started a booking|int', 'booked' => 'Booked|int'];

        $checkin = ['date' => 'Check-in date|date'] + $columns + ['demand' => 'Searched + started|int'] + ($compare ? ['prev_demand' => 'Previous searched + started|int', 'change' => 'Change|change'] : []);
        $rows = $this->byCheckin();

        if ($compare) {
            $before = array_column($this->byCheckin($this->period->compareFrom, $this->period->compareTo), 'demand', 'date');
            $rows = array_map(function (array $row) use ($before): array {
                $previous = (int) ($before[$row['date']] ?? 0);

                return $row + ['prev_demand' => $previous, 'change' => $previous > 0 ? ($row['demand'] - $previous) / $previous : null];
            }, $rows);
        }

        [$rows, $page] = $options->forTable('demand-checkin', 'date', ReportTable::sortableKeys($checkin), 'asc')->paginate($rows);
        // The CSV of this table has one row per date, stay length and country (the table "demand-detail").
        $tables = ['demand-checkin' => ReportTable::make('demand-checkin', 'Searches by check-in date', $checkin, $rows,
            'Guests\' searches in the booking engine (GA4 event counts, not people) for stays that start today or later, counted over the report period.'.$note, $page, 'demand-detail')];

        $stay = ['name' => 'Length of stay|text|-'] + $columns;
        [$rows, $page] = $options->forTable('demand-stay', 'nights', ['looked', 'started', 'booked', 'nights'], 'asc')->paginate($this->byStay());
        $tables['demand-stay'] = ReportTable::make('demand-stay', 'Searches by length of stay', $stay, $rows, 'Needs check-out dates (or a nights field) in GA4.', $page);

        // Only for the CSV (every row asked for), so the page does not run this query.
        if ($options->perPage === 0) {
            $detail = $this->detailRows();

            if ($compare) {
                $before = [];
                foreach ($this->detailRows($this->period->compareFrom, $this->period->compareTo) as $row) {
                    $before[$row['date'].'|'.($row['nights'] ?? '').'|'.$row['country']] = $row['demand'];
                }
                $detail = array_map(function (array $row) use ($before): array {
                    $previous = (int) ($before[$row['date'].'|'.($row['nights'] ?? '').'|'.$row['country']] ?? 0);

                    return $row + ['prev_demand' => $previous, 'change' => $previous > 0 ? ($row['demand'] - $previous) / $previous : null];
                }, $detail);
            }

            $columns = ['date' => 'Check-in date|date', 'nights' => 'Stay length (nights)|int', 'country' => 'Country|text'] + $columns + ['demand' => 'Searched + started|int'] + ($compare ? ['prev_demand' => 'Previous searched + started|int', 'change' => 'Change|change'] : []);
            $tables['demand-detail'] = ReportTable::make('demand-detail', 'Searches by check-in date, stay length and country', $columns, $detail, $this->country !== null && $this->country !== '' ? 'Only for the country chosen in the report.' : null);
        }

        return $tables;
    }

    private function events(?Carbon $from = null, ?Carbon $to = null, bool $filterCountry = true): Builder
    {
        $query = DB::table('ga4_booking_dates_daily')->where('hotel_id', $this->hotel->id)
            ->whereBetween('date', [($from ?? $this->period->from)->toDateString(), ($to ?? $this->period->to)->toDateString()]);

        if ($filterCountry && $this->country !== null && $this->country !== '') {
            $this->country === self::UNKNOWN_FILTER ? $query->whereIn('country', ['', '(not set)']) : $query->where('country', $this->country);
        }

        return $query;
    }

    /**
     * One row per check-in date, stay length and country (what the CSV has), with the searches, started bookings and
     * bookings, and - when a comparison is on - the same rows of the comparison period.
     *
     * @return list<array<string, scalar|null>>
     */
    public function detailRows(?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->events($from, $to)->where('checkin', '>=', now()->toDateString())->groupByRaw('checkin, nights, event_name, '.self::countryGroup())
            ->selectRaw('checkin, nights, event_name, '.self::countryGroup().' as country_name, sum(events) as events')->get();
        $detail = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->checkin)->toDateString();
            $nights = $row->nights === null ? null : (int) $row->nights;
            $country = $row->country_name === '' ? self::UNKNOWN : (string) $row->country_name;
            $key = $date.'|'.($nights ?? '').'|'.$country;

            $detail[$key] ??= ['date' => $date, 'nights' => $nights, 'country' => $country, 'looked' => 0, 'started' => 0, 'booked' => 0];
            $detail[$key][self::COLUMNS[$row->event_name] ?? 'looked'] += (int) $row->events;
        }

        $detail = array_map(fn (array $row) => $row + ['demand' => $row['looked'] + $row['started']], $detail);
        uasort($detail, fn ($a, $b) => [$a['date'], $a['nights'] ?? -1, $a['country']] <=> [$b['date'], $b['nights'] ?? -1, $b['country']]);

        return array_values($detail);
    }
}
