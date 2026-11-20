@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    // Validation error bag (Fortify uses named bags for profile/password forms).
    'bag' => 'default',
    'id' => null,
])

@php
    $id ??= 'field-'.str_replace(['.', '[', ']'], '-', $name);
    $error = $errors->getBag($bag)->first($name);
    $describedBy = collect([$help ? $id.'-help' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
@endphp

<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           {{ $attributes->class(['form-control', 'is-invalid' => $error]) }}
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           @if ($error) aria-invalid="true" @endif>
    @if ($help)
        <div id="{{ $id }}-help" class="form-text">{{ $help }}</div>
    @endif
    @if ($error)
        <div id="{{ $id }}-error" class="invalid-feedback">{{ $error }}</div>
    @endif
</div>
