{{-- Brand icons (generated from public/images/cv-favicon.png). ?v= makes browsers fetch a changed icon (APP_ASSET_VERSION). --}}
@php($v = '?v='.config('citevanta.asset_version'))
<link rel="icon" href="{{ asset('favicon.ico').$v }}" sizes="16x16 32x32 48x48">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg').$v }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icon-192.png').$v }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png').$v }}">
<link rel="manifest" href="{{ asset('site.webmanifest').$v }}">
<meta name="theme-color" content="#171717">
