@props([
    'id',
    'options' => [],
    'multiple' => false,
    'max' => null,
    'placeholder' => 'Type to search…',
    'invalid' => false,
    'describedby' => null,
])

{{--
    Accessible combobox with type-ahead. Bind with wire:model="property";
    the value is a string (single) or an array of strings (multiple).
--}}
<div {{ $attributes->whereStartsWith('wire:model') }}
     x-data="{
        value: @js($multiple ? [] : ''),
        options: @js(array_values($options)),
        multiple: @js((bool) $multiple),
        max: @js($max),
        query: '',
        open: false,
        active: 0,
        get chosen() { return this.multiple ? (this.value || []) : [] },
        get full() { return this.multiple && this.max !== null && this.chosen.length >= this.max },
        get filtered() {
            const q = this.query.trim().toLowerCase();
            const showAll = !this.multiple && this.query === this.value;
            return this.options
                .filter(o => showAll || !q || o.toLowerCase().includes(q))
                .filter(o => !this.multiple || !this.chosen.includes(o))
                .slice(0, 60);
        },
        init() {
            if (!this.multiple) { this.query = this.value || ''; }
            this.$watch('value', v => { if (!this.multiple && !this.open) { this.query = v || ''; } });
        },
        openList() { if (this.full) return; this.open = true; this.active = 0; },
        close() {
            this.open = false;
            if (!this.multiple) { this.query = this.value || ''; } else { this.query = ''; }
        },
        choose(option) {
            if (option === undefined) return;
            if (this.multiple) {
                if (this.full) return;
                this.value = [...this.chosen, option];
                this.query = '';
                if (this.full) { this.open = false; }
            } else {
                this.value = option;
                this.query = option;
                this.open = false;
            }
        },
        remove(option) { this.value = this.chosen.filter(v => v !== option); this.$refs.input.focus(); },
        move(step) {
            if (!this.open) { this.openList(); return; }
            const count = this.filtered.length;
            if (count) { this.active = (this.active + step + count) % count; }
            this.$nextTick(() => this.$refs.list?.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' }));
        },
     }"
     x-modelable="value"
     x-on:click.outside="close()"
     class="cv-select position-relative">

    <template x-if="multiple && chosen.length">
        <ul class="cv-select__chips list-unstyled d-flex flex-wrap gap-1 mb-2" aria-label="Selected">
            <template x-for="option in chosen" :key="option">
                <li class="cv-select__chip">
                    <span x-text="option"></span>
                    <button type="button" class="cv-select__chip-remove" x-on:click="remove(option)" :aria-label="'Remove ' + option">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </li>
            </template>
        </ul>
    </template>

    <input id="{{ $id }}" type="text" x-ref="input" autocomplete="off"
           @class(['form-control', 'is-invalid' => $invalid])
           role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-list"
           x-bind:aria-expanded="open.toString()"
           x-bind:aria-activedescendant="open && filtered.length ? '{{ $id }}-opt-' + active : null"
           @if ($describedby) aria-describedby="{{ $describedby }}" @endif
           @if ($invalid) aria-invalid="true" @endif
           placeholder="{{ $placeholder }}"
           x-bind:disabled="full"
           x-bind:placeholder="full ? 'Maximum reached' : '{{ $placeholder }}'"
           x-model="query"
           x-on:focus="openList()"
           x-on:input="openList(); active = 0"
           x-on:keydown.arrow-down.prevent="move(1)"
           x-on:keydown.arrow-up.prevent="move(-1)"
           x-on:keydown.enter.prevent="open && choose(filtered[active])"
           x-on:keydown.escape.prevent="close()"
           x-on:keydown.tab="close()"
           x-on:keydown.backspace="multiple && query === '' && chosen.length && remove(chosen[chosen.length - 1])">

    <ul id="{{ $id }}-list" x-ref="list" role="listbox" class="cv-select__list" x-show="open" x-cloak
        @if ($multiple) aria-multiselectable="true" @endif>
        <template x-for="(option, index) in filtered" :key="option">
            <li role="option" class="cv-select__option"
                :id="'{{ $id }}-opt-' + index"
                :data-active="index === active"
                :aria-selected="(multiple ? chosen.includes(option) : value === option).toString()"
                x-on:mousedown.prevent="choose(option)"
                x-on:mouseenter="active = index"
                x-text="option"></li>
        </template>
        <li class="cv-select__empty" x-show="filtered.length === 0">No matches</li>
    </ul>

    @if ($multiple && $max)
        <div class="form-text"><span x-text="chosen.length"></span> of {{ $max }} selected</div>
    @endif
</div>
