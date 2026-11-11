@props(['wordmark' => false])
@if($wordmark)
    <img src="{{ asset(config('branding.wordmark')) }}" alt="{{ config('branding.name') }}" width="240" height="51" class="img-fluid" />
@else
    <span class="d-inline-flex align-items-center gap-2"><img src="{{ asset(config('branding.icon')) }}" alt="" width="32" height="32" /><span>{{ config('branding.name') }}</span></span>
@endif
