{{-- Full logo for white backgrounds (e.g. emails); dark pages use the wordmark. Source: public/images/cv-logo.png. --}}
<a href="{{ $href ?? url('/') }}" class="cv-logo" aria-label="{{ config('app.name') }} home">
    <picture>
        <source srcset="{{ asset('images/logo-600.webp') }}" type="image/webp">
        <img src="{{ asset('images/logo-600.png') }}" width="240" height="53" alt="{{ config('app.name') }}">
    </picture>
</a>
