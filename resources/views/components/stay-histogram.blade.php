@props([
    // BookingDemandReport::stayHistogram()
    'stays',
])

@php
    $peak = max(array_column($stays['bars'], 'share')) ?: 1;
    $common = $stays['common'] >= \App\Services\Reports\BookingDemandReport::HISTOGRAM_MAX ? $stays['common'].' or more nights' : $stays['common'].' '.($stays['common'] === 1 ? 'night' : 'nights');
@endphp

<div class="cv-stays" id="stay-histogram">
    <div class="cv-stays__head"><span class="cv-cal__dot" aria-hidden="true"></span> Most common length of stay</div>
    <div class="cv-stays__headline">{{ $common }}</div>

    <div class="cv-stays__bars" aria-hidden="true">
        @foreach ($stays['bars'] as $bar)
            <div class="cv-stays__col" title="{{ $bar['label'] }} {{ $bar['n'] === 1 ? 'night' : 'nights' }}: {{ number_format($bar['share'] * 100, 1) }}%">
                <span class="cv-stays__bar" @class(['is-peak' => $bar['n'] === $stays['common']]) style="height: {{ $bar['demand'] > 0 ? max(3, round($bar['share'] / $peak * 100)) : 0 }}%"></span>
                <span class="cv-stays__label">{{ $bar['label'] }}</span>
            </div>
        @endforeach
    </div>
    <div class="cv-stays__axis" aria-hidden="true">nights · share of searches and started bookings</div>

    <table class="visually-hidden">
        <caption>Length of stay: share of searches and started bookings</caption>
        <thead><tr><th scope="col">Nights</th><th scope="col">Share</th></tr></thead>
        <tbody>
            @foreach ($stays['bars'] as $bar)
                <tr><th scope="row">{{ $bar['label'] }}</th><td>{{ number_format($bar['share'] * 100, 1) }}%</td></tr>
            @endforeach
        </tbody>
    </table>

    <p class="small text-muted mb-0" id="stay-summary">
        Stays of 1 to 3 nights were {{ number_format($stays['short_share'] * 100) }}% of the searches. From {{ number_format($stays['total']) }} searches and started bookings that chose a length.
    </p>
</div>
