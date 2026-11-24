<?php

namespace App\Services\Reports;

/**
 * One table's page, size and sort. perPage 0 = every row.
 */
final readonly class TableView
{
    public function __construct(public int $perPage, public int $page, public string $sort, public string $dir) {}

    /** A page past the end is the last page (so a stale link shows rows, not an empty table). */
    public function clamped(int $total): self
    {
        $last = $this->perPage > 0 ? max(1, (int) ceil($total / $this->perPage)) : 1;

        return new self($this->perPage, min($this->page, $last), $this->sort, $this->dir);
    }

    public function offset(): int
    {
        return $this->perPage > 0 ? ($this->page - 1) * $this->perPage : 0;
    }

    /**
     * Sort and slice rows that were built in PHP (small derived tables). Sorting covers all rows, not only the page.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @return array{0: list<array<string, scalar|null>>, 1: TablePage}
     */
    public function paginate(array $rows): array
    {
        $dir = $this->dir === 'asc' ? 1 : -1;
        $sort = $this->sort;

        usort($rows, function (array $a, array $b) use ($sort, $dir): int {
            $x = $a[$sort] ?? null;
            $y = $b[$sort] ?? null;

            if ($x === null || $y === null) {
                return $x === $y ? 0 : ($x === null ? 1 : -1); // rows without a value always last
            }

            $order = is_string($x) || is_string($y) ? strcasecmp((string) $x, (string) $y) : $x <=> $y;

            return $order !== 0 ? $order * $dir : strcasecmp((string) ($a['name'] ?? $a['date'] ?? ''), (string) ($b['name'] ?? $b['date'] ?? ''));
        });

        $total = count($rows);
        $view = $this->clamped($total);

        return [$this->perPage > 0 ? array_slice($rows, $view->offset(), $this->perPage) : $rows, $view->pageInfo($total)];
    }

    public function pageInfo(int $total): TablePage
    {
        $last = $this->perPage > 0 ? max(1, (int) ceil($total / $this->perPage)) : 1;
        $page = min($this->page, $last);

        return new TablePage(
            total: $total,
            page: $page,
            perPage: $this->perPage,
            lastPage: $last,
            from: $total === 0 ? 0 : (($page - 1) * max($this->perPage, 0)) + 1,
            to: $this->perPage > 0 ? min($total, $page * $this->perPage) : $total,
            sort: $this->sort,
            dir: $this->dir,
        );
    }
}
