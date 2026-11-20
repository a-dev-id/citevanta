@props(['item'])

@php
    /** @var \App\Services\Navigation\MenuItem $item */
    $open = $item->hasChildren() && $item->isOpen();
    $submenuId = 'cv-subnav-'.Str::slug($item->key);
@endphp

<li @class(['cv-nav__item', 'is-open' => $open])>
    @if ($item->hasChildren())
        <button type="button" class="cv-nav__link" data-cv-submenu-toggle
                aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $submenuId }}" title="{{ $item->label }}">
            <i class="fa-solid {{ $item->icon }} cv-nav__icon" aria-hidden="true"></i>
            <span class="cv-nav__label">{{ $item->label }}</span>
            @if ($item->badge)
                <span class="cv-nav__badge">{{ $item->badge }}</span>
            @endif
            <i class="fa-solid fa-angle-right cv-nav__caret" aria-hidden="true"></i>
        </button>

        <ul id="{{ $submenuId }}" class="cv-subnav" @unless ($open) hidden @endunless>
            @foreach ($item->children as $child)
                <x-sidebar-item :item="$child" />
            @endforeach
        </ul>
    @else
        <a href="{{ $item->url ?? '#' }}"
           @class(['cv-nav__link', 'is-active' => $item->active])
           @if ($item->active) aria-current="page" @endif
           @if ($item->url === null) aria-disabled="true" @endif
           title="{{ $item->label }}">
            <i class="fa-solid {{ $item->icon }} cv-nav__icon" aria-hidden="true"></i>
            <span class="cv-nav__label">{{ $item->label }}</span>
            @if ($item->badge)
                <span class="cv-nav__badge">{{ $item->badge }}</span>
            @endif
        </a>
    @endif
</li>
