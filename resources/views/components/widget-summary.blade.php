@props([
    'title',
    'value',
    'icon' => 'fa-chart-line',
    'variant' => 'primary',
    // Difference versus the previous scan; null = no previous scan.
    'change' => null,
    'changeUnit' => 'pts',
    'changeLabel' => 'vs last scan',
    'precision' => 1,
    // true when a lower number is better (e.g. average position). The arrow and
    // wording then show better/worse ("0.9 places worse"), not the raw sign.
    'lowerIsBetter' => false,
    'loading' => false,
])

@php
    $isGood = $change !== null && $change != 0 && (($change > 0) xor $lowerIsBetter);

    if ($lowerIsBetter) {
        // Arrow points up for better, down for worse; text says which.
        $arrow = $isGood ? 'fa-arrow-up' : 'fa-arrow-down';
        $formatted = $change === null ? null
            : number_format(abs($change), $precision).($changeUnit !== '' ? ' '.$changeUnit : '').($isGood ? ' better' : ' worse');
    } else {
        $arrow = $change > 0 ? 'fa-arrow-up' : 'fa-arrow-down';
        $formatted = $change === null ? null
            : ($change > 0 ? '+' : '').number_format($change, $precision).($changeUnit !== '' ? ' '.$changeUnit : '');
    }
@endphp

<section {{ $attributes->class(['card cv-card cv-widget-card']) }}>
    <div class="card-body">
        <div class="cv-widget">
            @if ($loading)
                <span class="cv-skeleton cv-skeleton--circle" aria-hidden="true"></span>
                <div class="flex-grow-1" aria-busy="true">
                    <span class="cv-skeleton w-50"></span>
                    <span class="cv-skeleton cv-skeleton--title"></span>
                    <span class="cv-skeleton w-75 mb-0"></span>
                    <span class="visually-hidden">Loading {{ $title }}</span>
                </div>
            @else
                <span class="cv-widget__icon cv-widget__icon--{{ $variant }}" aria-hidden="true">
                    <i class="fa-solid {{ $icon }}"></i>
                </span>
                <div class="min-w-0">
                    <h2 class="cv-widget__title">{{ $title }}</h2>
                    <p class="cv-widget__amount">{{ $value }}</p>
                    <p class="cv-widget__change mb-0">
                        @if ($change === null)
                            No previous scan to compare
                        @elseif ($change == 0)
                            No change {{ $changeLabel }}
                        @else
                            <span @class(['is-up' => $isGood, 'is-down' => ! $isGood])>
                                <i class="fa-solid {{ $arrow }}" aria-hidden="true"></i>
                                {{ $formatted }}
                            </span>
                            {{ $changeLabel }}
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>
</section>
