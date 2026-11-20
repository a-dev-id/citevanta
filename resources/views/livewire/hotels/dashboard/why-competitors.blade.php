{{-- Inside a prompt's accordion panel: why competitors were recommended instead. $why from DashboardService::withCompetitorReasons. --}}
@php
    $names = collect($why['competitors']);
    $title = 'Why '.($names->count() > 1 ? $names->slice(0, -1)->implode(', ').' and '.$names->last().' were' : $names->first().' was').' recommended instead';
@endphp

<section class="cv-why mb-3" aria-label="{{ $title }}">
    <h4 class="cv-why__title"><i class="fa-solid fa-chess-knight me-1" aria-hidden="true"></i> {{ $title }}</h4>
    @if ($why['summary'])
        <p class="small mb-2">{{ $why['summary'] }}</p>
    @endif

    @foreach ($why['reasons'] as $competitor => $reasons)
        <p class="small fw-semibold mb-1">{{ $competitor }}</p>
        <ul class="cv-why__reasons">
            @foreach ($reasons as $reason)
                <li>
                    <span class="fw-semibold">{{ $reason['reason'] }}</span>
                    <x-badge-status :label="$reason['hotel_has']->label()" :variant="$reason['hotel_has']->variant()" />
                    @if ($reason['quote'])
                        <span class="d-block text-muted">“{{ $reason['quote'] }}”</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach

    @if ($why['sources'])
        <p class="small fw-semibold mb-1">Sources that recommend competitors but not you</p>
        <p class="small mb-0">
            @foreach ($why['sources'] as $source)
                <span class="text-nowrap">{{ $source['domain'] }}</span>@if (! $loop->last), @endif
            @endforeach
        </p>
    @endif
</section>
