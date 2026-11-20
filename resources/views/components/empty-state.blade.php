@props([
    'icon' => 'fa-inbox',
    'text',
    'actionLabel' => null,
    'actionUrl' => null,
    'actionIcon' => 'fa-plus',
])

<div {{ $attributes->class('cv-empty') }}>
    <span class="cv-empty__icon" aria-hidden="true"><i class="fa-solid {{ $icon }}"></i></span>
    <p class="cv-empty__text">{{ $text }}</p>

    @if ($actionLabel)
        <a href="{{ $actionUrl ?? '#' }}" class="btn btn-primary">
            <i class="fa-solid {{ $actionIcon }} me-1" aria-hidden="true"></i>{{ $actionLabel }}
        </a>
    @endif

    {{ $slot }}
</div>
