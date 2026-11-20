@props([
    'title',
    // Either ['Section' => url, 'Page' => null] or a list of ['label' => ..., 'url' => ...].
    'breadcrumbs' => [],
])

@php
    $crumbs = collect($breadcrumbs)->map(fn ($value, $key) => is_array($value)
        ? ['label' => $value['label'], 'url' => $value['url'] ?? null]
        : ['label' => $key, 'url' => $value])->values();
@endphp

<header {{ $attributes->class('cv-page-header') }}>
    <h1 class="cv-page-header__title">{{ $title }}</h1>

    <div class="cv-page-header__actions">
        {{ $slot }}

        <nav aria-label="Breadcrumb">
            <ol class="breadcrumb cv-breadcrumbs">
                <li class="breadcrumb-item">
                    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/') }}">
                        <i class="fa-solid fa-house" aria-hidden="true"></i><span class="visually-hidden">Home</span>
                    </a>
                </li>
                @foreach ($crumbs as $crumb)
                    @if ($loop->last || ! $crumb['url'])
                        <li class="breadcrumb-item active" @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</li>
                    @else
                        <li class="breadcrumb-item"><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a></li>
                    @endif
                @endforeach
            </ol>
        </nav>
    </div>
</header>
