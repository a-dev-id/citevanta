@props([
    // A ScanStatus/Sentiment enum or its string value, e.g. "completed", "positive".
    'status' => null,
    'label' => null,
    // Force a colour: success, warning, danger, info, primary, neutral.
    'variant' => null,
    'icon' => null,
])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;

    [$defaultVariant, $defaultIcon, $defaultLabel] = match ($value) {
        'completed' => ['success', 'fa-circle-check', 'Completed'],
        'completed_with_errors' => ['warning', 'fa-triangle-exclamation', 'Completed with errors'],
        'failed' => ['danger', 'fa-circle-xmark', 'Failed'],
        'running' => ['primary', 'fa-circle-notch fa-spin', 'Running'],
        'queued', 'pending' => ['info', 'fa-clock', 'Queued'],
        'positive' => ['success', 'fa-face-smile', 'Positive'],
        'neutral' => ['neutral', 'fa-face-meh', 'Neutral'],
        'negative' => ['danger', 'fa-face-frown', 'Negative'],
        'not_mentioned' => ['neutral', 'fa-eye-slash', 'Not mentioned'],
        default => ['neutral', null, Str::of($value)->replace('_', ' ')->ucfirst()->toString()],
    };
@endphp

<span {{ $attributes->class(['cv-badge', 'cv-badge--'.($variant ?? $defaultVariant)]) }}>
    @if ($icon ?? $defaultIcon)
        <i class="fa-solid {{ $icon ?? $defaultIcon }}" aria-hidden="true"></i>
    @endif
    {{ $label ?? $defaultLabel }}
</span>
