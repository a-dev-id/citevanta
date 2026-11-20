@props([
    'title' => null,
    'subtitle' => null,
    'collapsible' => true,
    'collapsed' => false,
    // true dispatches a "card-refresh" browser event; a string calls that Livewire method.
    'refresh' => null,
    // Remove body padding, e.g. for edge-to-edge tables.
    'flush' => false,
    'bodyClass' => '',
])

<section {{ $attributes->class(['card cv-card']) }}
         x-data="{ open: @js(! $collapsed) }"
         x-bind:class="{ 'is-collapsed': ! open }">
    @if ($title || isset($actions) || $refresh || $collapsible)
        <header class="card-header">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="cv-card__title">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="cv-card__subtitle">{{ $subtitle }}</p>
                @endif
            </div>

            <div class="cv-card__actions">
                {{ $actions ?? '' }}

                @if ($refresh)
                    <button type="button" class="cv-card__action" aria-label="Refresh {{ $title }}"
                            @if (is_string($refresh)) wire:click="{{ $refresh }}" @else x-on:click="$dispatch('card-refresh')" @endif>
                        <i class="fa-solid fa-rotate" aria-hidden="true"
                           @if (is_string($refresh)) wire:loading.class="fa-spin" wire:target="{{ $refresh }}" @endif></i>
                    </button>
                @endif

                @if ($collapsible)
                    <button type="button" class="cv-card__action cv-card__action--collapse"
                            x-on:click="open = ! open" x-bind:aria-expanded="open.toString()"
                            aria-expanded="{{ $collapsed ? 'false' : 'true' }}" aria-label="Collapse {{ $title }}">
                        <i class="fa-solid fa-caret-down" aria-hidden="true"></i>
                    </button>
                @endif
            </div>
        </header>
    @endif

    <div class="cv-card__inner" x-show="open" x-collapse @if ($collapsed) style="display: none" @endif>
        <div @class(['card-body', 'cv-card__body--flush' => $flush, $bodyClass])>
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="card-footer">{{ $footer }}</div>
        @endisset
    </div>
</section>
