{{--
    Brand on dark backgrounds (app header, public site, login): the white-text
    logo (public/images/cv-logo-white.png, web copies logo-white-600.*).
    The alt text is config('app.name'); after a rename, replace the images.
    White backgrounds (emails) use layouts/partials/logo.
--}}
@php($brand = (string) config('app.name'))
<a href="{{ $href ?? url('/') }}" class="cv-wordmark" aria-label="{{ $brand }} home">
    <picture>
        <source srcset="{{ asset('images/logo-white-600.webp') }}" type="image/webp">
        <img src="{{ asset('images/logo-white-600.png') }}" width="600" height="132" alt="{{ $brand }}" class="cv-wordmark__logo">
    </picture>
</a>
