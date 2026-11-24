<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use App\Services\Google\AiSources;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Visits, purchases and revenue from AI assistants (GA4, our synced table
 * ga4_traffic_daily). An AI visit is a session whose source is one of the
 * configured AI domains or utm values (config citevanta.ai_sources).
 *
 * Only sessions, purchases and revenue are added up. User counts are never
 * summed across rows: the same person appears in many rows.
 */
final class AiTrafficReport
{
    private const LIMIT = 25;

    /** @var array<string, list<string>> */
    private array $sourceCache = [];

    private ?Carbon $completeThrough = null;

    private bool $completeThroughKnown = false;

    public function __construct(private readonly Hotel $hotel, private readonly ReportPeriod $period) {}

    /** Last day fully copied from GA4, or null when nothing is stored. */
    public function completeThrough(): ?Carbon
    {
        if (! $this->completeThroughKnown) {
            $this->completeThrough = ReportAvailability::ga4CompleteThrough($this->hotel);
            $this->completeThroughKnown = true;
        }

        return $this->completeThrough;
    }

    /** The report period reaches past the days that are copied, so the latest days are blank. */
    public function isIncomplete(): bool
    {
        return $this->completeThrough() !== null && $this->completeThrough()->lt($this->period->to);
    }

    /**
     * AI sessions, purchases and revenue, and their share of everything, for the period and (when a comparison is chosen and its days are synced) the comparison period.
     *
     * @return array{current: array<string, float|int>, previous: array<string, float|int>|null}
     */
    public function summary(): array
    {
        return [
            'current' => $this->totals($this->period->from, $this->period->to),
            'previous' => $this->period->hasComparison() ? $this->totals($this->period->compareFrom, $this->period->compareTo) : null,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    public function totals(Carbon $from, Carbon $to): array
    {
        $all = $this->base($from, $to)->selectRaw('coalesce(sum(sessions),0) as sessions, coalesce(sum(purchases),0) as purchases, coalesce(sum(revenue),0) as revenue')->first();
        $sources = $this->aiSources($from, $to);
        $ai = $sources === []
            ? (object) ['sessions' => 0, 'purchases' => 0, 'revenue' => 0]
            : $this->base($from, $to)->whereIn('source', $sources)->selectRaw('coalesce(sum(sessions),0) as sessions, coalesce(sum(purchases),0) as purchases, coalesce(sum(revenue),0) as revenue')->first();

        return [
            'ai_sessions' => (int) $ai->sessions, 'ai_purchases' => (int) $ai->purchases, 'ai_revenue' => round((float) $ai->revenue, 2),
            'all_sessions' => (int) $all->sessions, 'all_purchases' => (int) $all->purchases, 'all_revenue' => round((float) $all->revenue, 2),
            'session_share' => (int) $all->sessions > 0 ? (int) $ai->sessions / (int) $all->sessions : 0.0,
            'purchase_share' => (int) $all->purchases > 0 ? (int) $ai->purchases / (int) $all->purchases : 0.0,
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function byPlatform(): array
    {
        $sources = $this->aiSources($this->period->from, $this->period->to);
        $platforms = [];

        if ($sources !== []) {
            foreach ($this->grouped('source', $sources) as $row) {
                $platform = (string) AiSources::platform($row->name);
                $platforms[$platform] ??= ['name' => $platform, 'sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
                $platforms[$platform]['sessions'] += (int) $row->sessions;
                $platforms[$platform]['purchases'] += (int) $row->purchases;
                $platforms[$platform]['revenue'] += (float) $row->revenue;
            }
        }

        return $this->withShare(array_values($platforms), 'platform');
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function byCountry(): array
    {
        return $this->withShare($this->groupedRows('country'), 'country');
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function byLandingPage(): array
    {
        return $this->withShare($this->groupedRows('landing_page'), 'page');
    }

    /**
     * Charts: AI sessions per day, week or 30 days, and the same for purchases, each with the comparison (dashed) when one is chosen.
     * Days that are not synced yet are blank, never zero.
     *
     * @return array{sessions: array<string, mixed>, purchases: array<string, mixed>}
     */
    public function charts(): array
    {
        $size = $this->period->bucketDays();
        $count = (int) ceil($this->period->days() / $size);
        $current = $this->blankUnsynced($this->buckets($this->period->from, $this->period->to, $size, $count), $size);
        $previous = $this->period->hasComparison() ? $this->buckets($this->period->compareFrom, $this->period->compareTo, $size, $count) : null;

        $labels = [];
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $this->period->from->copy()->addDays($i * $size);
            $end = $start->copy()->addDays($size - 1)->min($this->period->to);
            $labels[] = $start->format('j M');
            $titles[] = $size === 1 ? $start->format('D j M Y') : $start->format('j M').' to '.$end->format('j M Y');
        }

        $build = function (string $metric, string $label) use ($current, $previous, $labels, $titles): array {
            $series = [['key' => 'this', 'label' => $label, 'color' => 'series-1', 'data' => array_column($current, $metric), 'group' => 'all', 'highlight' => true]];

            if ($previous !== null) {
                $series[] = ['key' => 'last', 'label' => $label.', '.$this->period->compareName(), 'color' => 'series-3', 'data' => array_column($previous, $metric), 'group' => 'all', 'highlight' => false, 'dash' => true];
            }

            return ['labels' => $labels, 'titles' => $titles, 'series' => $series, 'unit' => ''];
        };

        return ['sessions' => $build('sessions', 'AI sessions'), 'purchases' => $build('purchases', 'AI purchases')];
    }

    /**
     * The three tables, one page of rows each (or every row for the CSV), with previous and change columns when a comparison is on.
     *
     * @return array<string, ReportTable>
     */
    public function tables(?TableOptions $options = null): array
    {
        $options ??= new TableOptions;
        $compare = $this->period->hasComparison();
        $tables = [];

        foreach (['ai-platforms' => ['By AI platform', 'AI platform'], 'ai-countries' => ['By country', 'Country'], 'ai-pages' => ['By landing page', 'Landing page']] as $key => [$title, $first]) {
            $columns = $this->columns($first, $compare);
            $view = $options->forTable($key, 'sessions', ReportTable::sortableKeys($columns));
            [$rows, $page] = $key === 'ai-platforms'
                ? $view->paginate($this->platformRows($compare))
                : $this->groupedPage($key === 'ai-countries' ? 'country' : 'landing_page', $view, $compare);

            $tables[$key] = ReportTable::make($key, $title, $columns, $rows, $compare ? 'Compared with '.$this->period->compareLabel().' ('.$this->period->compareName().'). Rows that only existed in the comparison period are not listed.' : null, $page);
        }

        return $tables;
    }

    /**
     * @return array<string, string>
     */
    private function columns(string $first, bool $compare): array
    {
        return ['name' => $first.'|text', 'sessions' => 'AI sessions|int']
            + ($compare ? ['prev_sessions' => 'Previous sessions|int', 'change' => 'Change|change'] : [])
            + ['share' => 'Share of AI sessions|percent', 'purchases' => 'Purchases|int', 'revenue' => 'Revenue|money', 'conversion' => 'Purchases per session|percent'];
    }

    /**
     * The AI sources seen in the period or the comparison period.
     *
     * @return list<string>
     */
    private function sourcesAcross(): array
    {
        $from = $this->period->hasComparison() ? $this->period->from->copy()->min($this->period->compareFrom) : $this->period->from;
        $to = $this->period->hasComparison() ? $this->period->to->copy()->max($this->period->compareTo) : $this->period->to;

        return $this->aiSources($from, $to);
    }

    /**
     * Country and landing page: sorted and paged in the database.
     *
     * @return array{0: list<array<string, scalar|null>>, 1: TablePage}
     */
    private function groupedPage(string $column, TableView $view, bool $compare): array
    {
        $sources = $this->sourcesAcross();
        $total = (int) $this->totals($this->period->from, $this->period->to)['ai_sessions'];

        if ($sources === []) {
            return [[], $view->pageInfo(0)];
        }

        $query = new GroupedQuery(
            $column,
            fn () => $this->base($this->period->from, $this->period->to)->whereIn('source', $sources),
            $compare ? fn () => $this->base($this->period->compareFrom, $this->period->compareTo)->whereIn('source', $sources) : null,
            'sum(sessions) as sessions, sum(purchases) as purchases, sum(revenue) as revenue',
            ['sessions'],
            [
                'name' => 'c.name', 'sessions' => 'c.sessions', 'share' => 'c.sessions', 'purchases' => 'c.purchases', 'revenue' => 'c.revenue',
                'conversion' => '(c.purchases * 1.0 / nullif(c.sessions, 0))', 'prev_sessions' => 'coalesce(p.sessions, 0)',
                'change' => 'case when coalesce(p.sessions, 0) > 0 then (c.sessions - p.sessions) * 1.0 / p.sessions end',
            ],
        );
        [$found, $count] = $query->page($view);

        $rows = array_map(function (object $row) use ($total, $compare): array {
            $sessions = (int) $row->sessions;
            $out = ['name' => $row->name === '' ? '(not set)' : (string) $row->name, 'sessions' => $sessions];

            if ($compare) {
                $previous = (int) $row->prev_sessions;
                $out += ['prev_sessions' => $previous, 'change' => $previous > 0 ? ($sessions - $previous) / $previous : null];
            }

            return $out + [
                'share' => $total > 0 ? $sessions / $total : 0.0, 'purchases' => (int) $row->purchases, 'revenue' => round((float) $row->revenue, 2),
                'conversion' => $sessions > 0 ? (int) $row->purchases / $sessions : 0.0,
            ];
        }, $found);

        return [$rows, $view->pageInfo($count)];
    }

    /**
     * AI platforms (a handful of rows, grouped from the sources in PHP).
     *
     * @return list<array<string, scalar|null>>
     */
    private function platformRows(bool $compare): array
    {
        $sources = $this->sourcesAcross();
        $total = (int) $this->totals($this->period->from, $this->period->to)['ai_sessions'];
        $read = function (Carbon $from, Carbon $to) use ($sources): array {
            $platforms = [];

            if ($sources !== []) {
                foreach ($this->base($from, $to)->whereIn('source', $sources)->groupBy('source')->selectRaw('source as name, sum(sessions) as sessions, sum(purchases) as purchases, sum(revenue) as revenue')->get() as $row) {
                    $platform = (string) AiSources::platform($row->name);
                    $platforms[$platform] ??= ['sessions' => 0, 'purchases' => 0, 'revenue' => 0.0];
                    $platforms[$platform]['sessions'] += (int) $row->sessions;
                    $platforms[$platform]['purchases'] += (int) $row->purchases;
                    $platforms[$platform]['revenue'] += (float) $row->revenue;
                }
            }

            return $platforms;
        };
        $now = $read($this->period->from, $this->period->to);
        $before = $compare ? $read($this->period->compareFrom, $this->period->compareTo) : [];
        $rows = [];

        foreach ($now as $name => $values) {
            $row = ['name' => $name, 'sessions' => $values['sessions']];

            if ($compare) {
                $previous = (int) ($before[$name]['sessions'] ?? 0);
                $row += ['prev_sessions' => $previous, 'change' => $previous > 0 ? ($values['sessions'] - $previous) / $previous : null];
            }

            $rows[] = $row + [
                'share' => $total > 0 ? $values['sessions'] / $total : 0.0, 'purchases' => $values['purchases'], 'revenue' => round($values['revenue'], 2),
                'conversion' => $values['sessions'] > 0 ? $values['purchases'] / $values['sessions'] : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * Raw AI sources seen in the period, for finding totals.
     *
     * @return list<string>
     */
    public function aiSources(Carbon $from, Carbon $to): array
    {
        $key = $from->toDateString().'|'.$to->toDateString();

        return $this->sourceCache[$key] ??= $this->base($from, $to)->distinct()->pluck('source')
            ->filter(fn ($source) => AiSources::platform((string) $source) !== null)->values()->all();
    }

    private function base(Carbon $from, Carbon $to): Builder
    {
        return DB::table('ga4_traffic_daily')->where('hotel_id', $this->hotel->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @param  list<string>  $sources
     * @return Collection<int, object>
     */
    private function grouped(string $column, array $sources)
    {
        return $this->base($this->period->from, $this->period->to)->whereIn('source', $sources)
            ->groupBy($column)->selectRaw("{$column} as name, sum(sessions) as sessions, sum(purchases) as purchases, sum(revenue) as revenue")
            ->orderByDesc('sessions')->get();
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function groupedRows(string $column): array
    {
        $sources = $this->aiSources($this->period->from, $this->period->to);

        if ($sources === []) {
            return [];
        }

        return $this->grouped($column, $sources)->take(self::LIMIT)->map(fn ($row) => [
            'name' => $row->name === '' ? '(not set)' : $row->name,
            'sessions' => (int) $row->sessions, 'purchases' => (int) $row->purchases, 'revenue' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     * @return list<array<string, scalar|null>>
     */
    private function withShare(array $rows, string $label): array
    {
        $total = array_sum(array_column($rows, 'sessions'));
        usort($rows, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        return array_map(fn (array $row) => $row + [
            'share' => $total > 0 ? $row['sessions'] / $total : 0.0,
            'conversion' => $row['sessions'] > 0 ? $row['purchases'] / $row['sessions'] : 0.0,
            'revenue' => round((float) $row['revenue'], 2),
        ], $rows);
    }

    /**
     * Buckets that end after the last fully copied day are blank (null), not zero.
     *
     * @param  list<array{sessions: int, purchases: int}>  $buckets
     * @return list<array{sessions: int|null, purchases: int|null}>
     */
    private function blankUnsynced(array $buckets, int $size): array
    {
        $through = $this->completeThrough();

        if ($through === null || ! $this->isIncomplete()) {
            return $buckets;
        }

        foreach (array_keys($buckets) as $i) {
            $end = $this->period->from->copy()->addDays(($i + 1) * $size - 1)->min($this->period->to);

            if ($end->gt($through)) {
                $buckets[$i] = ['sessions' => null, 'purchases' => null];
            }
        }

        return $buckets;
    }

    /**
     * @return list<array{sessions: int, purchases: int}>
     */
    private function buckets(Carbon $from, Carbon $to, int $size, int $count): array
    {
        $buckets = array_fill(0, $count, ['sessions' => 0, 'purchases' => 0]);
        $sources = $this->aiSources($from, $to);

        if ($sources === []) {
            return $buckets;
        }

        $rows = $this->base($from, $to)->whereIn('source', $sources)->groupBy('date')->selectRaw('date, sum(sessions) as sessions, sum(purchases) as purchases')->get();

        foreach ($rows as $row) {
            $index = intdiv((int) $from->diffInDays(Carbon::parse($row->date)->startOfDay()), $size);

            if (isset($buckets[$index])) {
                $buckets[$index]['sessions'] += (int) $row->sessions;
                $buckets[$index]['purchases'] += (int) $row->purchases;
            }
        }

        return $buckets;
    }
}
