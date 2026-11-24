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
     * Check-in demand per week (the week's Monday), for the chart.
     *
     * @return array<string, mixed>
     */
    public function chart(): array
    {
        $weeks = [];

        foreach ($this->byCheckin() as $row) {
            $monday = Carbon::parse($row['date'])->startOfWeek()->toDateString();
            $weeks[$monday] ??= ['looked' => 0, 'started' => 0, 'booked' => 0];
            foreach (['looked', 'started', 'booked'] as $column) {
                $weeks[$monday][$column] += $row[$column];
            }
        }

        ksort($weeks);
        $weeks = array_slice($weeks, 0, 26, true);
        $labels = array_map(fn ($monday) => Carbon::parse($monday)->format('j M'), array_keys($weeks));

        return [
            'labels' => $labels,
            'titles' => array_map(fn ($monday) => 'Week of '.Carbon::parse($monday)->format('j M Y'), array_keys($weeks)),
            'series' => [
                ['key' => 'looked', 'label' => 'Looked at rooms', 'color' => 'series-1', 'data' => array_column($weeks, 'looked'), 'group' => 'all', 'highlight' => false],
                ['key' => 'started', 'label' => 'Started a booking', 'color' => 'series-2', 'data' => array_column($weeks, 'started'), 'group' => 'all', 'highlight' => false],
                ['key' => 'booked', 'label' => 'Booked', 'color' => 'series-3', 'data' => array_column($weeks, 'booked'), 'group' => 'all', 'highlight' => false],
            ],
            'unit' => '',
        ];
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
     * Check-in dates with demand (looked + started at least $minimum), soonest first, within $days days ahead.
     *
     * @return list<string>
     */
    public function demandDates(int $minimum, int $days): array
    {
        $until = now()->addDays($days)->toDateString();

        return array_values(array_map(
            fn (array $row) => $row['date'],
            array_filter($this->byCheckin(), fn (array $row) => $row['demand'] >= $minimum && $row['date'] <= $until),
        ));
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
