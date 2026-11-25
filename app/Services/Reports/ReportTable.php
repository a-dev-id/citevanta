<?php

namespace App\Services\Reports;

/**
 * One report table: shown on the page (one page of rows, sorted) and exported as CSV (all rows) from the same
 * data. Column types: text, int, money, percent (0..1), decimal, date. A column spec is "Label|type"; add
 * "|-" for a column that cannot be sorted. A "change" type column is a fraction (0.25 = +25%) or null.
 */
final readonly class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, type: string, sortable: bool}>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?string $note = null,
        public ?TablePage $page = null,
        public ?string $exportKey = null,
    ) {}

    /**
     * @param  array<string, string>  $columns  key => "Label|type" or "Label|type|-"
     * @param  list<array<string, scalar|null>>  $rows
     */
    public static function make(string $key, string $title, array $columns, array $rows, ?string $note = null, ?TablePage $page = null, ?string $exportKey = null): self
    {
        return new self($key, $title, self::definitions($columns), $rows, $note, $page, $exportKey);
    }

    /**
     * @param  array<string, string>  $columns
     * @return list<array{key: string, label: string, type: string, sortable: bool}>
     */
    public static function definitions(array $columns): array
    {
        $defs = [];
        foreach ($columns as $column => $definition) {
            [$label, $type, $flag] = array_pad(explode('|', $definition, 3), 3, '');
            $defs[] = ['key' => $column, 'label' => $label, 'type' => $type ?: 'text', 'sortable' => $flag !== '-'];
        }

        return $defs;
    }

    /**
     * @param  array<string, string>  $columns
     * @return list<string>
     */
    public static function sortableKeys(array $columns): array
    {
        return array_column(array_filter(self::definitions($columns), fn (array $column) => $column['sortable']), 'key');
    }

    /**
     * Rows for the CSV file: raw values in column order.
     *
     * @return list<list<scalar|null>>
     */
    public function csvRows(): array
    {
        return array_map(
            fn (array $row) => array_map(fn (array $column) => $row[$column['key']] ?? null, $this->columns),
            $this->rows,
        );
    }

    /**
     * @return list<string>
     */
    public function csvHeader(): array
    {
        return array_column($this->columns, 'label');
    }
}
