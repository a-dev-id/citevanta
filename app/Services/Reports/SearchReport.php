<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Google Search (Search Console, our synced table gsc_daily): top queries,
 * pages and countries, seasonal query groups and low-click-rate
 * opportunities. The click rate is clicks divided by impressions, and the
 * position is averaged weighted by impressions.
 *
 * Search Console does not give data about AI Overviews through its API, so
 * AI report impressions cannot be shown (see AI_OVERVIEWS_NOTE).
 */
final class SearchReport
{
    public const AI_OVERVIEWS_NOTE = 'Impressions in Google AI Overviews and AI Mode are not available through the Search Console API, so they are not shown here. They are counted inside your normal Search Console totals in Google itself.';

    private const LIMIT = 25;

    public function __construct(private readonly Hotel $hotel, private readonly ReportPeriod $period) {}

    /**
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    public function totals(?Carbon $from = null, ?Carbon $to = null): array
    {
        $row = $this->base($from, $to)->selectRaw('coalesce(sum(clicks),0) as clicks, coalesce(sum(impressions),0) as impressions, coalesce(sum(position * impressions),0) as weighted')->first();
        $impressions = (int) $row->impressions;

        return [
            'clicks' => (int) $row->clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? (int) $row->clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? round((float) $row->weighted / $impressions, 1) : 0.0,
        ];
    }

    /**
     * The totals of the comparison period, or null when no comparison is chosen.
     *
     * @return array{clicks: int, impressions: int, ctr: float, position: float}|null
     */
    public function previousTotals(): ?array
    {
        return $this->period->hasComparison() ? $this->totals($this->period->compareFrom, $this->period->compareTo) : null;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function queries(): array
    {
        return $this->grouped('query', self::LIMIT);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function pages(): array
    {
        return $this->grouped('page', self::LIMIT);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function countries(): array
    {
        return array_map(fn (array $row) => ['name' => strtoupper($row['name'])] + $row, $this->grouped('country', self::LIMIT));
    }

    /**
     * Queries that mention a season or holiday, grouped (e.g. Christmas, Nyepi, a month name).
     *
     * @return list<array<string, scalar|null>>
     */
    public function seasonalGroups(?Carbon $from = null, ?Carbon $to = null): array
    {
        $groups = [];

        foreach ($this->grouped('query', 2000, $from, $to) as $row) {
            $query = mb_strtolower((string) $row['name']);

            foreach ((array) config('citevanta.reports.seasonal_groups') as $group => $words) {
                foreach ($words as $word) {
                    if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $query)) {
                        $groups[$group] ??= ['name' => $group, 'queries' => 0, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'top' => $row['name'], 'top_impressions' => 0];
                        $groups[$group]['queries']++;
                        $groups[$group]['clicks'] += $row['clicks'];
                        $groups[$group]['impressions'] += $row['impressions'];
                        $groups[$group]['weighted'] += $row['position'] * $row['impressions'];

                        if ($row['impressions'] > $groups[$group]['top_impressions']) {
                            $groups[$group]['top'] = $row['name'];
                            $groups[$group]['top_impressions'] = $row['impressions'];
                        }

                        break;
                    }
                }
            }
        }

        $rows = array_map(fn (array $g) => [
            'name' => $g['name'], 'queries' => $g['queries'], 'clicks' => $g['clicks'], 'impressions' => $g['impressions'],
            'ctr' => $g['impressions'] > 0 ? $g['clicks'] / $g['impressions'] : 0.0,
            'position' => $g['impressions'] > 0 ? round($g['weighted'] / $g['impressions'], 1) : 0.0,
            'top' => $g['top'],
        ], array_values($groups));
        usort($rows, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

        return $rows;
    }

    /**
     * Queries people see often, in a good position, but rarely click: a better title or description on the page can win those clicks.
     *
     * @return list<array<string, scalar|null>>
     */
    public function opportunities(?int $limit = self::LIMIT): array
    {
        $min = (int) config('citevanta.reports.opportunity_min_impressions');
        $maxCtr = (float) config('citevanta.reports.opportunity_max_ctr');
        $maxPosition = (float) config('citevanta.reports.opportunity_max_position');

        $rows = array_filter($this->grouped('query', 2000), fn (array $row) => $row['impressions'] >= $min && $row['ctr'] < $maxCtr && $row['position'] < $maxPosition);
        usort($rows, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

        $rows = array_values($rows);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    /**
     * The page that gets most impressions for a query (the page to improve).
     */
    public function topPageFor(string $query): ?string
    {
        $page = $this->base()->where('query', $query)->groupBy('page')->selectRaw('page, sum(impressions) as impressions')->orderByDesc('impressions')->value('page');

        return $page !== null ? (string) $page : null;
    }

    /**
     * The tables, one page of rows each (or every row for the CSV), sorted over all rows, with previous and change columns when a comparison is on.
     *
     * @return array<string, ReportTable>
     */
    public function tables(?TableOptions $options = null): array
    {
        $options ??= new TableOptions;
        $compare = $this->period->hasComparison();
        $note = $compare ? 'Compared with '.$this->period->compareLabel().' ('.$this->period->compareName().').' : null;
        $tables = [];

        foreach (['search-queries' => ['Top queries', 'Query', 'query'], 'search-pages' => ['Top pages', 'Page', 'page'], 'search-countries' => ['Top countries', 'Country', 'country']] as $key => [$title, $first, $column]) {
            $columns = $this->columns($first, $compare);
            $view = $options->forTable($key, 'clicks', ReportTable::sortableKeys($columns));
            [$rows, $page] = $this->groupedPage($column, $view, $compare);
            $tables[$key] = ReportTable::make($key, $title, $columns, $rows, $note, $page);
        }

        $columns = ['name' => 'Group|text', 'queries' => 'Queries|int', 'clicks' => 'Clicks|int']
            + ($compare ? ['prev_clicks' => 'Previous clicks|int', 'change' => 'Change|change'] : [])
            + ['impressions' => 'Impressions|int', 'ctr' => 'Click rate|percent', 'position' => 'Average position|decimal', 'top' => 'Biggest query|text|-'];
        $view = $options->forTable('search-seasonal', 'clicks', ReportTable::sortableKeys($columns));
        $rows = $this->seasonalGroups();

        if ($compare) {
            $before = array_column($this->seasonalGroups($this->period->compareFrom, $this->period->compareTo), 'clicks', 'name');
            $rows = array_map(fn (array $row) => $this->withChange($row, (int) ($before[$row['name']] ?? 0)), $rows);
        }

        [$rows, $page] = $view->paginate($rows);
        $tables['search-seasonal'] = ReportTable::make('search-seasonal', 'Seasonal query groups', $columns, $rows, trim('A query counts in the first group whose words it contains. '.$note), $page);

        $columns = $this->columns('Query', $compare);
        $view = $options->forTable('search-opportunities', 'clicks', ReportTable::sortableKeys($columns));
        $rows = $this->opportunities(null);

        if ($compare) {
            $before = array_column($this->grouped('query', 2000, $this->period->compareFrom, $this->period->compareTo), 'clicks', 'name');
            $rows = array_map(fn (array $row) => $this->withChange($row, (int) ($before[$row['name']] ?? 0)), $rows);
        }

        [$rows, $page] = $view->paginate($rows);
        $tables['search-opportunities'] = ReportTable::make('search-opportunities', 'Low click rate opportunities', $columns, $rows,
            trim('At least '.config('citevanta.reports.opportunity_min_impressions').' impressions, a click rate below '.round((float) config('citevanta.reports.opportunity_max_ctr') * 100).'% and an average position better than '.config('citevanta.reports.opportunity_max_position').'. '.$note), $page);

        return $tables;
    }

    /**
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    private function withChange(array $row, int $previous): array
    {
        return $row + ['prev_clicks' => $previous, 'change' => $previous > 0 ? ((int) $row['clicks'] - $previous) / $previous : null];
    }

    /**
     * @return array<string, string>
     */
    private function columns(string $first, bool $compare): array
    {
        return ['name' => $first.'|text', 'clicks' => 'Clicks|int']
            + ($compare ? ['prev_clicks' => 'Previous clicks|int', 'change' => 'Change|change'] : [])
            + ['impressions' => 'Impressions|int', 'ctr' => 'Click rate|percent', 'position' => 'Average position|decimal'];
    }

    /**
     * Queries, pages and countries: sorted and paged in the database.
     *
     * @return array{0: list<array<string, scalar|null>>, 1: TablePage}
     */
    private function groupedPage(string $column, TableView $view, bool $compare): array
    {
        $query = new GroupedQuery(
            $column,
            fn () => $this->base(),
            $compare ? fn () => $this->base($this->period->compareFrom, $this->period->compareTo) : null,
            'sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) as weighted',
            ['clicks'],
            [
                'name' => 'c.name', 'clicks' => 'c.clicks', 'impressions' => 'c.impressions',
                'ctr' => '(c.clicks * 1.0 / nullif(c.impressions, 0))', 'position' => '(c.weighted * 1.0 / nullif(c.impressions, 0))',
                'prev_clicks' => 'coalesce(p.clicks, 0)',
                'change' => 'case when coalesce(p.clicks, 0) > 0 then (c.clicks - p.clicks) * 1.0 / p.clicks end',
            ],
        );
        [$found, $total] = $query->page($view);

        $rows = array_map(function (object $row) use ($column, $compare): array {
            $impressions = (int) $row->impressions;
            $clicks = (int) $row->clicks;
            $out = ['name' => $column === 'country' ? strtoupper((string) $row->name) : (string) $row->name, 'clicks' => $clicks];

            if ($compare) {
                $previous = (int) $row->prev_clicks;
                $out += ['prev_clicks' => $previous, 'change' => $previous > 0 ? ($clicks - $previous) / $previous : null];
            }

            return $out + [
                'impressions' => $impressions, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
                'position' => $impressions > 0 ? round((float) $row->weighted / $impressions, 1) : 0.0,
            ];
        }, $found);

        return [$rows, $view->pageInfo($total)];
    }

    private function base(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        return DB::table('gsc_daily')->where('hotel_id', $this->hotel->id)
            ->whereBetween('date', [($from ?? $this->period->from)->toDateString(), ($to ?? $this->period->to)->toDateString()]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function grouped(string $column, int $limit, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->base($from, $to)->groupBy($column)
            ->selectRaw("{$column} as name, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) as weighted")
            ->orderByDesc('impressions')->limit($limit)->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                'ctr' => (int) $row->impressions > 0 ? (int) $row->clicks / (int) $row->impressions : 0.0,
                'position' => (int) $row->impressions > 0 ? round((float) $row->weighted / (int) $row->impressions, 1) : 0.0,
            ])->all();
    }
}
