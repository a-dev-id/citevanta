<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use Illuminate\Database\Query\Builder;
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
    public function totals(): array
    {
        $row = $this->base()->selectRaw('coalesce(sum(clicks),0) as clicks, coalesce(sum(impressions),0) as impressions, coalesce(sum(position * impressions),0) as weighted')->first();
        $impressions = (int) $row->impressions;

        return [
            'clicks' => (int) $row->clicks, 'impressions' => $impressions,
            'ctr' => $impressions > 0 ? (int) $row->clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? round((float) $row->weighted / $impressions, 1) : 0.0,
        ];
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
    public function seasonalGroups(): array
    {
        $groups = [];

        foreach ($this->grouped('query', 2000) as $row) {
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
    public function opportunities(): array
    {
        $min = (int) config('citevanta.reports.opportunity_min_impressions');
        $maxCtr = (float) config('citevanta.reports.opportunity_max_ctr');
        $maxPosition = (float) config('citevanta.reports.opportunity_max_position');

        $rows = array_filter($this->grouped('query', 2000), fn (array $row) => $row['impressions'] >= $min && $row['ctr'] < $maxCtr && $row['position'] < $maxPosition);
        usort($rows, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

        return array_slice(array_values($rows), 0, self::LIMIT);
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
     * @return array<string, ReportTable>
     */
    public function tables(): array
    {
        $columns = fn (string $first) => [
            'name' => $first.'|text', 'clicks' => 'Clicks|int', 'impressions' => 'Impressions|int', 'ctr' => 'Click rate|percent', 'position' => 'Average position|decimal',
        ];

        return [
            'search-queries' => ReportTable::make('search-queries', 'Top queries', $columns('Query'), $this->queries()),
            'search-pages' => ReportTable::make('search-pages', 'Top pages', $columns('Page'), $this->pages()),
            'search-countries' => ReportTable::make('search-countries', 'Top countries', $columns('Country'), $this->countries()),
            'search-seasonal' => ReportTable::make('search-seasonal', 'Seasonal query groups', [
                'name' => 'Group|text', 'queries' => 'Queries|int', 'clicks' => 'Clicks|int', 'impressions' => 'Impressions|int',
                'ctr' => 'Click rate|percent', 'position' => 'Average position|decimal', 'top' => 'Biggest query|text',
            ], $this->seasonalGroups(), 'A query counts in the first group whose words it contains.'),
            'search-opportunities' => ReportTable::make('search-opportunities', 'Low click rate opportunities', $columns('Query'), $this->opportunities(),
                'At least '.config('citevanta.reports.opportunity_min_impressions').' impressions, a click rate below '.round((float) config('citevanta.reports.opportunity_max_ctr') * 100).'% and an average position better than '.config('citevanta.reports.opportunity_max_position').'.'),
        ];
    }

    private function base(): Builder
    {
        return DB::table('gsc_daily')->where('hotel_id', $this->hotel->id)
            ->whereBetween('date', [$this->period->from->toDateString(), $this->period->to->toDateString()]);
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function grouped(string $column, int $limit): array
    {
        return $this->base()->groupBy($column)
            ->selectRaw("{$column} as name, sum(clicks) as clicks, sum(impressions) as impressions, sum(position * impressions) as weighted")
            ->orderByDesc('impressions')->limit($limit)->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                'ctr' => (int) $row->impressions > 0 ? (int) $row->clicks / (int) $row->impressions : 0.0,
                'position' => (int) $row->impressions > 0 ? round((float) $row->weighted / (int) $row->impressions, 1) : 0.0,
            ])->all();
    }
}
