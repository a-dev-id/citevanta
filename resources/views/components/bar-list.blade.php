@props([
    // list of ['name' => string, 'sessions' => int, 'share' => float 0..1]
    'rows',
    'title',
    'total' => null,
    'unit' => 'visits',
])

@php
    $peak = max(array_column($rows, 'share')) ?: 1;
@endphp

<div class="cv-barlist">
    <div class="cv-barlist__head">
        <span>{{ $title }}</span>
        @if ($total !== null)<span>{{ number_format($total) }} {{ $unit }}</span>@endif
    </div>
    <ul class="list-unstyled mb-0">
        @foreach ($rows as $row)
            <li class="cv-barlist__row">
                <span class="cv-barlist__name">{{ $row['name'] }}</span>
                <span class="cv-barlist__track" aria-hidden="true"><span class="cv-barlist__fill" style="width: {{ round($row['share'] / $peak * 100) }}%"></span></span>
                <span class="cv-barlist__value">{{ number_format($row['sessions']) }} · {{ number_format($row['share'] * 100) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
