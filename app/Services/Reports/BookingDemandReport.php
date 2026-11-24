<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use App\Services\Google\GoogleSync;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demand by check-in date and by length of stay, from the booking date
 * fields GA4 receives on view_item (looked), begin_checkout (started) and
 * purchase (booked) events. Event counts, not people. Only the events that
 * happened in the report period are used, so a date's demand is what guests
 * looked for during that time. Empty until the fields are mapped.
 */
final class BookingDemandReport
{
    public const TOP_DATES = 15;

    public const LONGEST_STAY = 10;

    public const HISTOGRAM_MAX = 15;

    private const COLUMNS = ['view_item' => 'looked', 'begin_checkout' => 'started', 'purchase' => 'booked'];

    public function __construct(private readonly Hotel $hotel, private readonly ReportPeriod $period) {}

    /**
     * Per check-in date, oldest first, only dates from today on.
     *
     * @return list<array<string, scalar|null>>
     */
    public function byCheckin(): array
    {
        $rows = $this->events()->where('checkin', '>=', now()->toDateString())->groupBy('checkin', 'event_name')
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
     * The dates with the most interest (looked + started), most first.
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
     * Data for the check-in calendar: for every date from today on, what guests looked at (l), started
     * booking (s) and booked (b), the demand (d = l + s), and the stay lengths behind it ([nights, l, s, b],
     * most asked first; nights 0 = not known). Short keys, because this goes to the browser as JSON.
     *
     * @return array{days: array<string, array<string, mixed>>, max: int, top: array{date: string, demand: int}|null, today: string}
     */
    public function calendar(): array
    {
        $rows = $this->events()->where('checkin', '>=', now()->toDateString())->groupBy('checkin', 'nights', 'event_name')
            ->selectRaw('checkin, nights, event_name, sum(events) as events')->get();
        $days = [];
        $column = ['view_item' => 'l', 'begin_checkout' => 's', 'purchase' => 'b'];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->checkin)->toDateString();
            $key = $column[$row->event_name] ?? 'l';
            $nights = (int) $row->nights;

            $days[$date] ??= ['l' => 0, 's' => 0, 'b' => 0, 'd' => 0, 'stays' => []];
            $days[$date]['stays'][$nights] ??= ['l' => 0, 's' => 0, 'b' => 0];
            $days[$date][$key] += (int) $row->events;
            $days[$date]['stays'][$nights][$key] += (int) $row->events;
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
     * How long guests want to stay: the share of looks and started bookings per number of nights
     * (1 to 14, then 15 or more). Null when no stay length is known.
     *
     * @return array{bars: list<array{n: int, label: string, demand: int, share: float}>, total: int, common: int, short_share: float}|null
     */
    public function stayHistogram(): ?array
    {
        $rows = $this->events()->whereIn('event_name', ['view_item', 'begin_checkout'])->where('nights', '>=', 1)
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
    public function tables(): array
    {
        $columns = ['looked' => 'Looked at rooms|int', 'started' => 'Started a booking|int', 'booked' => 'Booked|int'];

        return [
            'demand-checkin' => ReportTable::make('demand-checkin', 'Demand by check-in date', ['date' => 'Check-in date|date'] + $columns + ['demand' => 'Looked + started|int'], $this->byCheckin(),
                'Event counts from GA4 for stays that start today or later, counted over the report period.'),
            'demand-stay' => ReportTable::make('demand-stay', 'Demand by length of stay', ['name' => 'Length of stay|text'] + $columns, $this->byStay(),
                'Needs check-out dates (or a nights field) in GA4.'),
        ];
    }

    private function events(): Builder
    {
        return DB::table('ga4_booking_dates_daily')->where('hotel_id', $this->hotel->id)
            ->whereIn('event_name', GoogleSync::BOOKING_EVENTS)
            ->whereBetween('date', [$this->period->from->toDateString(), $this->period->to->toDateString()]);
    }
}
