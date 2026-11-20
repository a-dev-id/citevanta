<nav id="cv-sidebar" class="cv-sidebar" aria-label="Main navigation">
    @foreach ($shell->menu as $heading => $items)
        <div class="cv-sidebar__heading" id="cv-nav-{{ Str::slug($heading) }}">{{ $heading }}</div>
        <ul class="cv-nav" aria-labelledby="cv-nav-{{ Str::slug($heading) }}">
            @foreach ($items as $item)
                <x-sidebar-item :item="$item" />
            @endforeach
        </ul>
    @endforeach
</nav>
<div class="cv-backdrop" data-cv-backdrop aria-hidden="true"></div>
