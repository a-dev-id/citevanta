@props([
    // App\Services\Reports\ReportTable
    'table',
    'hotel',
    'period',
    'currency' => '',
    // Show only the first rows (the CSV always has all of them).
    'limit' => null,
    'rows' => null,
])

@php
    $format = function (string $type, $value) use ($currency) {
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
    $shown = $rows ?? $table->rows;
    $shown = $limit ? array_slice($shown, 0, $limit) : $shown;
@endphp

<x-card :title="$table->title" :flush="true" :collapsible="false">
    <x-slot:actions>
        <a href="{{ route('hotels.reports.export', [$hotel, $table->key, 'range' => $period->key]) }}" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i>CSV
        </a>
    </x-slot:actions>

    @if ($shown === [])
        <x-empty-state icon="fa-table" text="Nothing to show for this date range." />
    @else
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        @foreach ($table->columns as $column)
                            <th scope="col" @class(['cv-num' => $column['type'] !== 'text'])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shown as $row)
                        <tr>
                            @foreach ($table->columns as $column)
                                <td @class(['cv-num' => $column['type'] !== 'text'])>{{ $format($column['type'], $row[$column['key']] ?? null) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($table->note)
        <p class="small text-muted px-3 pb-3 mb-0">{{ $table->note }}</p>
    @endif
</x-card>
