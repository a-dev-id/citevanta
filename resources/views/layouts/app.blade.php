@props([
    'title' => null,
    'breadcrumbs' => [],
    'activeMenu' => null,
    'shell' => null,
    'htmlClass' => null,
    // Browser tab title when there is no page title bar from the layout.
    'documentTitle' => null,
    // A (Livewire) page that renders its own x-page-header and .cv-content,
    // e.g. to put interactive buttons in the title bar.
    'bare' => false,
])

@php
    $shell ??= \App\Services\Navigation\ShellData::for(auth()->user(), $activeMenu);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class([$htmlClass])>
<head>
    @include('layouts.partials.head', ['title' => $title ?? $documentTitle])
</head>
<body class="cv-app">
    <a href="#main" class="cv-skip-link">Skip to content</a>

    @include('layouts.partials.header')
    @include('layouts.partials.sidebar')

    <main id="main" class="cv-main" tabindex="-1">
        @if ($bare)
            @include('layouts.partials.admin-alerts', ['wrap' => true])
            @include('layouts.partials.billing-banner', ['wrap' => true])
            {{ $slot }}
        @else
            @if ($title)
                <x-page-header :title="$title" :breadcrumbs="$breadcrumbs">
                    {{ $pageActions ?? '' }}
                </x-page-header>
            @endif

            <div class="cv-content">
                @include('layouts.partials.admin-alerts')
                @include('layouts.partials.billing-banner')
                {{ $slot }}
            </div>
        @endif

        <footer class="cv-footer">
            &copy; {{ now()->year }} {{ config('app.name') }} · AI visibility for hotels
            @if (auth()->user()?->is_admin)
                · <span title="Version and build date of this installation">{{ \App\Services\Deploy\ReleaseInfo::label() }}</span>
                @if ($uploadedRelease = \App\Services\Deploy\ReleaseInfo::uploaded())
                    · release <span title="Id of the uploaded zip (release.json)">{{ $uploadedRelease['id'] }}</span>
                @endif
            @endif
        </footer>
    </main>

    @livewireScripts
</body>
</html>
