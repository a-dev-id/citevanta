{{-- "?" next to a Type column header: one sentence per domain type, shown on hover or focus. --}}
@php
    $tooltip = '<dl class="cv-domain-help">'.collect(\App\Enums\DomainType::cases())
        ->map(fn ($type) => '<dt>'.e($type->label()).'</dt><dd>'.e($type->description()).'</dd>')
        ->implode('').'</dl>';
@endphp

<button type="button" {{ $attributes->class(['cv-help']) }}
        data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="bottom"
        data-bs-custom-class="cv-tooltip-wide" data-bs-title="{{ $tooltip }}"
        aria-label="What the domain types mean">?</button>
