@props(['bag' => 'default'])
{{-- Cloudflare Turnstile challenge; nothing when TURNSTILE_* keys are empty. --}}
@php($siteKey = app(\App\Services\Site\HumanVerifier::class)->siteKey())
@if ($siteKey)
    <div {{ $attributes->class(['cf-turnstile']) }} data-sitekey="{{ $siteKey }}" data-language="{{ app()->getLocale() }}"></div>
    @error('cf-turnstile-response', $bag)<div class="text-danger small">{{ $message }}</div>@enderror
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
