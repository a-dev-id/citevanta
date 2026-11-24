<?php

namespace App\Services\Reports;

/**
 * One report table: shown on the page and exported as CSV from the same data.
 * Column types: text, int, money, percent (0..1), decimal, date.
 */
final readonly class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<string, string>  $columns  key => "Label|type"
     * @param  list<array<string, scalar|null>>  $rows
     */
    public static function make(string $key, string $title, array $columns, array $rows, ?string $note = null): self
    {
        $defs = [];
        foreach ($columns as $column => $definition) {
            [$label, $type] = array_pad(explode('|', $definition, 2), 2, 'text');
            $defs[] = ['key' => $column, 'label' => $label, 'type' => $type];
        }

        return new self($key, $title, $defs, $rows, $note);
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
