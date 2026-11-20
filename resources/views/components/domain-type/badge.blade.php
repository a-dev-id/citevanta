@props([
    // A DomainType or its value, e.g. "ota".
    'type',
])

@php($type = $type instanceof \App\Enums\DomainType ? $type : \App\Enums\DomainType::from((string) $type))

<span {{ $attributes->class(['cv-badge', 'cv-domain', $type->cssClass()]) }}>{{ $type->label() }}</span>
