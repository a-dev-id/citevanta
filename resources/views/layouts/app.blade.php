<!doctype html>
<html lang="en" data-bs-theme="light" @auth data-bs-navbar-position="vertical" data-bs-layout="fluid" @endauth>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ?? 'Dashboard' }} &middot; {{ config('branding.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset(config('branding.icon')) }}">
    <meta name="description" content="{{ config('branding.description') }}">
    <link rel="stylesheet" href="{{ asset('vendor/tabler/tabler.min.css') }}?v={{ filemtime(public_path('vendor/tabler/tabler.min.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/intelligence.css') }}?v={{ filemtime(public_path('css/intelligence.css')) }}">
</head>
<body class="{{ auth()->check() ? '' : 'd-flex flex-column' }}">
<a class="visually-hidden-focusable skip-link" href="#content">Skip to main content</a>
<div class="page {{ auth()->guest() ? 'page-center' : '' }}">
    @auth
        <aside class="navbar navbar-vertical navbar-expand-lg" aria-label="Sidebar">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle sidebar navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="navbar-brand navbar-brand-autodark">
                    <a href="{{ route('dashboard') }}" aria-label="{{ config('branding.name') }} dashboard"><x-brand /></a>
                </div>
                <div class="navbar-footer">
                    <ul class="navbar-nav">
                        <li class="nav-item dropup">
                            <button class="nav-link dropdown-toggle w-100" type="button" id="account-menu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open account menu">
                                <span class="avatar avatar-sm bg-primary-lt">{{ mb_substr(auth()->user()->name,0,1) }}</span>
                                <span class="d-none d-lg-block ps-2 text-start">
                                    <span class="d-block">{{ auth()->user()->name }}</span>
                                    <span class="d-block mt-1 small text-secondary">{{ app(\App\Services\TenantContext::class)->roleLabel(auth()->user()) }}</span>
                                </span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow" aria-labelledby="account-menu">
                                <a class="dropdown-item" href="{{ route('settings') }}"><x-icon name="settings" class="dropdown-item-icon" /> AI Settings</a>
                                @if(auth()->user()->is_platform_admin)<a class="dropdown-item" href="{{ route('platform.organizations') }}">Platform administration</a>@endif
                                <div class="dropdown-divider"></div>
                                <form method="post" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="dropdown-item" type="submit"><x-icon name="logout" class="dropdown-item-icon" /> Log out</button>
                                </form>
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="collapse navbar-collapse" id="sidebar-menu">
                    @include('layouts.navigation')
                </div>
            </div>
        </aside>
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-fluid">
                <div class="navbar-brand fw-normal">{{ config('branding.name') }}</div>

            </div>
        </header>
        <div class="page-wrapper">
    @endauth
    <main id="content" class="{{ auth()->check() ? 'd-flex flex-column flex-fill' : 'container container-tight py-4' }}">
        @auth
            <div class="page-header d-print-none">
                <div class="container-fluid">
                    <div class="row g-2 align-items-center">
                        <div class="col-12 col-md">
                            <div class="page-pretitle">{{ config('branding.description') }}</div>
                            <h1 class="page-title">{{ $title ?? 'Dashboard' }}</h1>
                            <div class="text-secondary small mt-2">Current Workspace: {{ $workspace->brand()->name }}</div>
                        </div>
                        <div class="col-12 col-md-auto ms-md-auto d-print-none"><div class="btn-list">@yield('actions')</div></div>
                    </div>
                </div>
            </div>
            <div class="page-body"><div class="container-fluid">
            @include('organizations.switchers')
            @if(app(\App\Services\TenantContext::class)->organization()->is_test_fixture)<div class="alert alert-warning" data-simulated-notice><strong>SIMULATED TEST DATA</strong> — Demonstration workspace. Results are synthetic, not genuine AI visibility measurements. Only simulated subscription credits are used. No real provider requests are sent.</div>@endif
        @endauth
        @if(session('status'))
            <div class="alert alert-success" role="status">{{ \App\Services\MonitoringMessages::publicText(session('status')) }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ \App\Services\MonitoringMessages::publicText($error) }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
        @auth </div></div> @endauth
    </main>
    @auth
        <footer class="footer footer-transparent d-print-none">
            <div class="container-fluid">
                <div class="row text-center align-items-center flex-row-reverse">
                    <div class="col-lg-auto ms-lg-auto"><span class="status"><span class="status-dot status-green"></span>Manual search monitoring</span></div>
                    <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                        <ul class="list-inline list-inline-dots mb-0"><li class="list-inline-item">{{ config('branding.name') }}</li><li class="list-inline-item">{{ config('branding.tagline') }}</li></ul>
                    </div>
                </div>
            </div>
        </footer>
        </div>
    @endauth
</div>
<script src="{{ asset('vendor/tabler/tabler.min.js') }}?v={{ filemtime(public_path('vendor/tabler/tabler.min.js')) }}" defer></script>
<script src="{{ asset('js/intelligence.js') }}?v={{ filemtime(public_path('js/intelligence.js')) }}" defer></script>
</body>
</html>
