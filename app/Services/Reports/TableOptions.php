<?php

namespace App\Services\Reports;

use Illuminate\Http\Request;

/**
 * What the reader asked of the tables: rows per page (10, 50 or 100), and per table the page and the sort,
 * all kept in the URL (?size=50&page[ai-countries]=2&sort[ai-countries]=sessions:desc).
 * perPage 0 means every row (the CSV export).
 */
final readonly class TableOptions
{
    public const SIZES = [10, 50, 100];

    public const DEFAULT_SIZE = 10;

    /**
     * @param  array<string, mixed>  $pages  table key => page number
     * @param  array<string, mixed>  $sorts  table key => "column:asc|desc"
     */
    public function __construct(public int $perPage = self::DEFAULT_SIZE, public array $pages = [], public array $sorts = []) {}

    public static function fromRequest(Request $request, int $perPage): self
    {
        return new self($perPage, (array) $request->query('page', []), (array) $request->query('sort', []));
    }

    /** Every row, in the default order (CSV). */
    public static function all(array $sorts = []): self
    {
        return new self(0, [], $sorts);
    }

    public static function validSize(mixed $size): ?int
    {
        return is_numeric($size) && in_array((int) $size, self::SIZES, true) ? (int) $size : null;
    }

    /**
     * @param  list<string>  $sortable  allowed column keys
     */
    public function forTable(string $key, string $defaultSort, array $sortable, string $defaultDir = 'desc'): TableView
    {
        $sort = $defaultSort;
        $dir = $defaultDir;
        $asked = $this->sorts[$key] ?? null;

        if (is_string($asked) && preg_match('/^([a-z_]+):(asc|desc)$/', $asked, $m) && in_array($m[1], $sortable, true)) {
            [$sort, $dir] = [$m[1], $m[2]];
        }

        $page = $this->pages[$key] ?? 1;

        return new TableView($this->perPage, is_numeric($page) ? max(1, (int) $page) : 1, $sort, $dir);
    }
}
