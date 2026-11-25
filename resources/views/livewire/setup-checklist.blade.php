<div>
    @if ($visible)
        {{-- Expanded card, minimized pill, or (on a narrow screen) the pill and a bottom sheet. Esc minimizes. --}}
        <section class="cv-checklist" data-state="{{ $state }}" id="setup-checklist" role="region" aria-label="{{ __('checklist.ui.region') }}"
                 x-data="{ narrow: window.matchMedia('(max-width: 991.98px)').matches, sheet: false, menu: false }"
                 x-init="window.matchMedia('(max-width: 991.98px)').addEventListener('change', (e) => { narrow = e.matches; sheet = false; menu = false; })"
                 @keydown.escape.window="if (document.querySelector('.modal.show, .cv-tour')) { return; } if (menu) { menu = false; } else if (narrow) { sheet = false; } else if ($wire.state === 'expanded') { $wire.minimize(); }">

            {{-- The pill: minimized on a wide screen, always on a narrow one. --}}
            <button type="button" class="cv-checklist__pill" id="setup-checklist-pill" x-cloak
                    x-show="narrow ? !sheet : $wire.state === 'minimized'"
                    @click="narrow ? (sheet = true) : $wire.expand()"
                    aria-label="{{ __('checklist.ui.expand') }}">
                <span class="cv-checklist__ring" style="--p: {{ $progress['percent'] }}" aria-hidden="true"></span>
                <span>{{ $progress['complete'] ? __('checklist.ui.pill_all') : __('checklist.ui.pill', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>
            </button>

            <div class="cv-checklist__backdrop" x-cloak x-show="narrow && sheet" @click="sheet = false" aria-hidden="true"></div>

            {{-- The card. --}}
            <div class="cv-checklist__card" id="setup-checklist-card" x-cloak :class="{ 'is-sheet': narrow }"
                 x-show="narrow ? sheet : $wire.state === 'expanded'">
                <header class="cv-checklist__header">
                    <h2 class="cv-checklist__title">{{ $progress['complete'] ? __('checklist.ui.all_set') : __('checklist.ui.title') }}</h2>
                    <div class="cv-checklist__tools">
                        <div class="position-relative">
                            <button type="button" class="cv-checklist__tool" aria-label="{{ __('checklist.ui.menu') }}" :aria-expanded="menu ? 'true' : 'false'" aria-haspopup="true" @click="menu = !menu">
                                <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                            </button>
                            <div class="cv-checklist__menu" x-cloak x-show="menu" @click.outside="menu = false">
                                <button type="button" class="dropdown-item" id="setup-checklist-hide" wire:click="hide" @click="menu = false">
                                    <i class="fa-solid fa-eye-slash" aria-hidden="true"></i>{{ __('checklist.ui.hide') }}
                                </button>
                            </div>
                        </div>
                        <button type="button" class="cv-checklist__tool" id="setup-checklist-minimize" aria-label="{{ __('checklist.ui.minimize') }}"
                                @click="narrow ? (sheet = false) : $wire.minimize()">
                            <i class="fa-solid fa-minus" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>

                <div class="cv-checklist__body">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-semibold" id="setup-checklist-progress">{{ __('checklist.ui.progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>
                    </div>
                    <div class="progress mb-3" role="progressbar" aria-label="{{ __('checklist.ui.region') }}" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100" style="height: .5rem">
                        <div class="progress-bar" style="width: {{ $progress['percent'] }}%"></div>
                    </div>

                    @if ($progress['complete'])
                        <p class="small mb-3" id="setup-checklist-all-set">{{ __('checklist.ui.all_set_text') }}</p>
                    @endif

                    <ul class="cv-checklist__list list-unstyled mb-0">
                        @foreach ($items as $item)
                            <li @class(['cv-checklist__item', 'is-done' => $item['done'], 'is-extra' => ! $item['counted']]) data-item="{{ $item['key'] }}" data-done="{{ $item['done'] ? '1' : '0' }}">
                                <i @class(['fa-solid cv-checklist__icon', $item['icon'], 'text-success' => $item['done'], 'text-muted' => ! $item['done']]) aria-hidden="true"></i>
                                <div class="flex-grow-1 min-w-0">
                                    <span @class(['d-block', 'fw-semibold' => ! $item['done'], 'text-muted' => $item['done']])>{{ $item['title'] }}
                                        <span class="visually-hidden">({{ ! $item['counted'] ? __('checklist.ui.not_counted') : ($item['done'] ? __('checklist.ui.done') : __('checklist.ui.todo')) }})</span>
                                    </span>
                                    {{-- A done item is compact: the tick and the title only, with no help and no button, so the open items stand out. --}}
                                    @unless ($item['done'])
                                        <span class="d-block small text-muted">{{ $item['help'] }}</span>
                                        @if ($item['url'])
                                            <a href="{{ $item['url'] }}" class="btn btn-sm btn-outline-primary mt-1">{{ $item['action'] }}</a>
                                        @endif
                                    @endunless
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    @endif
</div>
