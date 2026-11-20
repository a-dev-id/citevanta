@props([
    'title',
    'description',
    // Page key from PublicPages::PAGES (adds canonical + hreflang), or null (e.g. an audit report).
    'page' => null,
    'noindex' => false,
    // JSON-LD string from SiteContent::jsonLd().
    'jsonLd' => null,
    // Load Livewire only on pages that use it (the audit report): keeps marketing pages light.
    'livewire' => false,
])

@php
    use App\Services\Site\PublicPages;

    $locale = app()->getLocale();
    $canonical = $page ? PublicPages::url($page, $locale) : url()->current();
    $ogImage = config('citevanta.site.og_image');
    $ogImage = $ogImage ? (str_starts_with($ogImage, 'http') ? $ogImage : url($ogImage)) : null;
    $app = config('app.name');
    $navLink = fn (string $key) => PublicPages::url($key, $locale);
@endphp

<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @else
        <link rel="canonical" href="{{ $canonical }}">
        @if ($page)
            @foreach (PublicPages::alternates($page) as $hreflang => $href)
                <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
            @endforeach
        @endif
    @endif

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $app }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $locale === 'id' ? 'id_ID' : 'en_US' }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    @include('layouts.partials.favicons')

    @vite(['resources/sass/public.scss', 'resources/js/public.js'])

    @if ($jsonLd)
        <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endif
    @if ($livewire)
        @livewireStyles
    @endif
</head>
<body class="cv-site">
    <a href="#main" class="cv-skip-link">Skip to content</a>

    <header class="cv-site-header">
        <nav class="navbar navbar-expand-lg navbar-dark container" aria-label="{{ __('site.nav.menu') }}">
            <span class="navbar-brand me-4 py-0">@include('layouts.partials.wordmark', ['href' => $navLink('home')])</span>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#site-nav"
                    aria-controls="site-nav" aria-expanded="false" aria-label="{{ __('site.nav.menu') }}">
                <x-site.icon name="bars" />
            </button>
            <div class="collapse navbar-collapse" id="site-nav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="{{ $navLink('home') }}#features">{{ __('site.nav.features') }}</a></li>
                    <li class="nav-item"><a @class(['nav-link', 'active' => $page === 'pricing']) href="{{ $navLink('pricing') }}" @if ($page === 'pricing') aria-current="page" @endif>{{ __('site.nav.pricing') }}</a></li>
                    <li class="nav-item"><a @class(['nav-link', 'active' => $page === 'free-audit']) href="{{ $navLink('free-audit') }}" @if ($page === 'free-audit') aria-current="page" @endif>{{ __('site.nav.free_audit') }}</a></li>
                </ul>
                <div class="d-flex flex-wrap align-items-center gap-3 py-2 py-lg-0">
                    <span class="cv-lang-switch" aria-label="{{ __('site.nav.language') }}">
                        @foreach (PublicPages::locales() as $code)
                            <a href="{{ PublicPages::switchUrl($page, $code) }}" hreflang="{{ $code }}" lang="{{ $code }}"
                               @if ($code === $locale) aria-current="true" @endif>{{ strtoupper($code) }}</a>@if (! $loop->last) <span aria-hidden="true">·</span> @endif
                        @endforeach
                    </span>
                    <a class="nav-link p-0" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">{{ __('site.nav.trial') }}</a>
                </div>
            </div>
        </nav>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="cv-site-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-5">
                    @include('layouts.partials.wordmark', ['href' => $navLink('home')])
                    <p class="mt-2 mb-0">{{ __('site.meta.tagline') }}</p>
                    <p class="small mt-2">{{ __('site.footer.note', ['app' => $app]) }}</p>
                </div>
                <div class="col-6 col-md-2">
                    <h2>{{ __('site.footer.product') }}</h2>
                    <ul>
                        <li><a href="{{ $navLink('home') }}#features">{{ __('site.nav.features') }}</a></li>
                        <li><a href="{{ $navLink('pricing') }}">{{ __('site.nav.pricing') }}</a></li>
                        <li><a href="{{ $navLink('free-audit') }}">{{ __('site.nav.free_audit') }}</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-2">
                    <h2>{{ __('site.footer.company') }}</h2>
                    <ul>
                        <li><a href="{{ $navLink('contact') }}">{{ __('site.contact.title') }}</a></li>
                        <li><a href="{{ route('login') }}">{{ __('site.nav.login') }}</a></li>
                    </ul>
                </div>
                <div class="col-6 col-md-3">
                    <h2>{{ __('site.footer.legal') }}</h2>
                    <ul>
                        <li><a href="{{ $navLink('privacy') }}">{{ __('site.legal.privacy_title') }}</a></li>
                        <li><a href="{{ $navLink('terms') }}">{{ __('site.legal.terms_title') }}</a></li>
                    </ul>
                </div>
            </div>
            <p class="small mt-4 mb-0">&copy; {{ now()->year }} {{ $app }}. {{ __('site.footer.rights') }}</p>
        </div>
    </footer>

    @if ($livewire)
        @livewireScripts
    @endif
</body>
</html>
