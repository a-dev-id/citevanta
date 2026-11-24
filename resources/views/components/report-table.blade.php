@props([
    // App\Services\Reports\ReportTable (one page of rows, sorted, with its TablePage)
    'table',
    'hotel',
    'period',
    'currency' => '',
])

@php
    use App\Services\Reports\TableOptions;

    $format = function (string $type, $value) use ($currency) {
        if ($type === 'change') {
            return $value === null ? '—' : (($value >= 0 ? '+' : '').number_format((float) $value * 100, 0).'%');
        }

        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'int' => number_format((float) $value),
            'money' => trim($currency.' '.number_format((float) $value, 0)),
            'percent' => number_format((float) $value * 100, 1).'%',
            'decimal' => number_format((float) $value, 1),
            'date' => \Illuminate\Support\Carbon::parse($value)->format('D j M Y'),
            default => $value,
        };
    };

    $page = $table->page;
    $anchor = '#table-'.$table->key;

    // A link to this table with other page, sort or size; every other table keeps its own page and sort.
    $link = function (array $changes) use ($table, $anchor): string {
        $query = request()->query();

        if (array_key_exists('size', $changes)) {
            $query['size'] = $changes['size'];
            unset($query['page']); // a new page size goes back to page 1 everywhere
        }

        if (array_key_exists('sort', $changes)) {
            $query['sort'][$table->key] = $changes['sort'];
            unset($query['page'][$table->key]);
        }

        if (array_key_exists('page', $changes)) {
            $query['page'][$table->key] = $changes['page'];
        }

        return url()->current().'?'.http_build_query(array_filter($query, fn ($value) => $value !== null && $value !== [])).$anchor;
    };
@endphp

<div id="table-{{ $table->key }}">
<x-card :title="$table->title" :flush="true" :collapsible="false">
    <x-slot:actions>
        <a href="{{ route('hotels.reports.export', [$hotel, $table->key] + $period->query() + array_filter(['sort' => request()->query('sort')])) }}" class="btn btn-sm btn-outline-secondary" title="All rows of this date range{{ $period->hasComparison() ? ', with the comparison columns' : '' }}">
            <i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>CSV
        </a>
    </x-slot:actions>

    @if ($page === null ? $table->rows === [] : $page->total === 0)
        <x-empty-state icon="fa-table" text="Nothing to show for this date range." />
    @else
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        @foreach ($table->columns as $column)
                            @php($active = $page !== null && $page->sort === $column['key'])
                            <th scope="col" @class(['cv-num' => $column['type'] !== 'text']) @if ($active) aria-sort="{{ $page->dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                @if ($column['sortable'] && $page !== null)
                                    <a class="text-reset text-decoration-none" data-sort="{{ $column['key'] }}"
                                       href="{{ $link(['sort' => $column['key'].':'.($active ? ($page->dir === 'desc' ? 'asc' : 'desc') : (in_array($column['type'], ['text', 'date'], true) ? 'asc' : 'desc'))]) }}">
                                        {{ $column['label'] }}
                                        <i @class(['fa-solid ms-1 small', 'fa-sort-down' => $active && $page->dir === 'desc', 'fa-sort-up' => $active && $page->dir === 'asc', 'fa-sort text-muted' => ! $active]) aria-hidden="true"></i>
                                    </a>
                                @else
                                    {{ $column['label'] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($table->columns as $column)
                                <td @class(['cv-num' => $column['type'] !== 'text', 'text-success' => $column['type'] === 'change' && ($row[$column['key']] ?? 0) > 0, 'text-danger' => $column['type'] === 'change' && ($row[$column['key']] ?? 0) < 0])>{{ $format($column['type'], $row[$column['key']] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($page !== null)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 pb-3" id="pager-{{ $table->key }}">
                <div class="d-flex flex-wrap align-items-center gap-3 small">
                    <span class="text-muted">Showing {{ number_format($page->from) }}–{{ number_format($page->to) }} of {{ number_format($page->total) }}</span>
                    <span class="d-inline-flex align-items-center gap-1" role="group" aria-label="Rows per page">
                        <span class="text-muted">Rows per page</span>
                        @foreach (TableOptions::SIZES as $size)
                            <a href="{{ $link(['size' => $size]) }}" @class(['btn btn-sm', 'btn-primary' => $size === $page->perPage, 'btn-outline-secondary' => $size !== $page->perPage]) @if ($size === $page->perPage) aria-current="true" @endif>{{ $size }}</a>
                        @endforeach
                    </span>
                </div>

                @if ($page->lastPage > 1)
                    <nav aria-label="{{ $table->title }} pages">
                        <ul class="pagination pagination-sm mb-0">
                            <li @class(['page-item', 'disabled' => $page->page <= 1])><a class="page-link" href="{{ $page->page > 1 ? $link(['page' => $page->page - 1]) : '#' }}" rel="prev">Previous</a></li>
                            @foreach ($page->pageNumbers() as $number)
                                @if ($number === null)
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                @else
                                    <li @class(['page-item', 'active' => $number === $page->page])><a class="page-link" href="{{ $link(['page' => $number]) }}" @if ($number === $page->page) aria-current="page" @endif>{{ $number }}</a></li>
                                @endif
                            @endforeach
                            <li @class(['page-item', 'disabled' => $page->page >= $page->lastPage])><a class="page-link" href="{{ $page->page < $page->lastPage ? $link(['page' => $page->page + 1]) : '#' }}" rel="next">Next</a></li>
                        </ul>
                    </nav>
                @endif
            </div>
        @endif
    @endif

    @if ($table->note)
        <p class="small text-muted px-3 pb-3 mb-0">{{ $table->note }}</p>
    @endif
</x-card>
</div>
