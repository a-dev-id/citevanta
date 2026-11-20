@props([
    'title' => null,
    'subtitle' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title])
</head>
<body>
    <main class="cv-auth">
        <div class="cv-auth__brand">
            @include('layouts.partials.wordmark', ['href' => url('/')])
        </div>

        <div class="card cv-auth__card">
            <div class="card-body">
                @if ($title)
                    <h1 class="cv-auth__title">{{ $title }}</h1>
                @endif
                @if ($subtitle)
                    <p class="cv-auth__subtitle">{{ $subtitle }}</p>
                @endif

                {{ $slot }}
            </div>
        </div>

        @isset($footer)
            <div class="cv-auth__footer">{{ $footer }}</div>
        @endisset
    </main>

    @livewireScripts
</body>
</html>
