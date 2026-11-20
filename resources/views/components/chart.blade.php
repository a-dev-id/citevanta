@props([
    // From TrendService: labels, titles, series (key, label, color, data, group, highlight), unit.
    'chart',
    'type' => 'line',
    'stacked' => false,
    // Series group shown first ("overall" / "personas"); series in group "all" always show.
    'group' => 'all',
    'label',
    'height' => 240,
])

@php
    $config = ['type' => $type, 'stacked' => (bool) $stacked, 'group' => $group] + $chart;
    $json = json_encode($config, JSON_THROW_ON_ERROR);
    $id = $attributes->get('id', 'cv-chart-'.substr(md5($json), 0, 10));
    $format = fn ($value) => $value === null ? '—' : $value.$chart['unit'];
@endphp

{{-- wire:ignore keeps Livewire away from the canvas Chart.js owns; the key changes with the data, so new data replaces it. --}}
<div class="cv-chart" style="height: {{ (int) $height }}px" wire:ignore wire:key="{{ $id }}">
    <canvas id="{{ $id }}" data-cv-chart="{{ $json }}" role="img" aria-label="{{ $label }}"></canvas>
</div>

<table class="visually-hidden">
    <caption>{{ $label }}</caption>
    <thead>
        <tr>
            <th scope="col">Scan</th>
            @foreach ($chart['series'] as $series)
                <th scope="col">{{ $series['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($chart['titles'] as $i => $title)
            <tr>
                <th scope="row">{{ $title }}</th>
                @foreach ($chart['series'] as $series)
                    <td>{{ $format($series['data'][$i] ?? null) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
