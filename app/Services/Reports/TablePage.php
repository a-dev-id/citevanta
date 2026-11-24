<?php

namespace App\Services\Reports;

/**
 * Where a table is: "Showing 11-20 of 143", the page and the sort in use.
 */
final readonly class TablePage
{
    public function __construct(
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
        public int $from,
        public int $to,
        public string $sort,
        public string $dir,
    ) {}

    /**
     * Page numbers to show: the first, the last and two either side of the current page, with null for a gap.
     *
     * @return list<int|null>
     */
    public function pageNumbers(): array
    {
        $numbers = array_values(array_unique(array_filter([1, $this->lastPage, ...range(max(1, $this->page - 2), min($this->lastPage, $this->page + 2))])));
        sort($numbers);
        $out = [];

        foreach ($numbers as $i => $number) {
            if ($i > 0 && $number - $numbers[$i - 1] > 1) {
                $out[] = null;
            }
            $out[] = $number;
        }

        return $out;
    }
}
