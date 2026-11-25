<?php

namespace App\Services\Reports;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A report table that groups synced rows by one column, sorted and paged in the database (limit and offset),
 * so a large table does not slow the page. The sort covers all groups. With a comparison, the previous
 * period's numbers are joined on the group name, so "previous" and "change" can be sorted too.
 */
final class GroupedQuery
{
    /**
     * @param  Closure(): Builder  $current  the filtered rows of the period
     * @param  Closure(): Builder|null  $previous  the same filter for the comparison period
     * @param  string  $currentSelect  aggregates, e.g. "sum(sessions) as sessions, sum(purchases) as purchases"
     * @param  list<string>  $previousMetrics  aggregate aliases also read from the previous period (they appear as prev_<alias>)
     * @param  array<string, string>  $sorts  sort key => SQL expression (over c.<alias>, prev_<alias>)
     */
    public function __construct(
        private readonly string $column,
        private readonly Closure $current,
        private readonly ?Closure $previous,
        private readonly string $currentSelect,
        private readonly array $previousMetrics,
        private readonly array $sorts,
    ) {}

    /**
     * @return array{0: list<object>, 1: int} the rows of the page and the number of groups
     */
    public function page(TableView $view): array
    {
        $current = ($this->current)()->groupByRaw($this->column)->selectRaw("{$this->column} as name, {$this->currentSelect}");
        $query = DB::query()->fromSub($current, 'c');
        $select = ['c.*'];

        if ($this->previous !== null) {
            $aggregates = implode(', ', array_map(fn (string $alias) => $this->previousAggregate($alias), $this->previousMetrics));
            $previous = ($this->previous)()->groupByRaw($this->column)->selectRaw("{$this->column} as name, {$aggregates}");
            $query->leftJoinSub($previous, 'p', 'p.name', '=', 'c.name');

            foreach ($this->previousMetrics as $alias) {
                $select[] = "coalesce(p.{$alias}, 0) as prev_{$alias}";
            }
        }

        $total = DB::query()->fromSub($current, 'c')->count();
        $view = $view->clamped($total);
        $expression = $this->sorts[$view->sort] ?? 'c.name';
        $direction = $view->dir === 'asc' ? 'asc' : 'desc';

        $query->selectRaw(implode(', ', $select))
            ->orderByRaw("({$expression}) is null asc")
            ->orderByRaw("({$expression}) {$direction}")
            ->orderBy('c.name');

        if ($view->perPage > 0) {
            $query->offset($view->offset())->limit($view->perPage);
        }

        return [$query->get()->all(), $total];
    }

    /** "sum(clicks) as clicks": the previous period is read with the same aggregate. */
    private function previousAggregate(string $alias): string
    {
        preg_match('/((?:sum|count|max|min)\([^)]*\))\s+as\s+'.preg_quote($alias, '/').'\b/i', $this->currentSelect, $m);

        return ($m[1] ?? "sum({$alias})")." as {$alias}";
    }
}
