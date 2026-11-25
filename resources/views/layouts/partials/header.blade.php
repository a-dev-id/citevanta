@php
    $homeUrl = Route::has('dashboard') ? route('dashboard') : url('/');
    $activeHotel = collect($shell->hotels)->firstWhere('active', true) ?? ($shell->hotels[0] ?? null);
@endphp

<header class="cv-header">
    <div class="cv-header__brand">
        @include('layouts.partials.wordmark', ['href' => $homeUrl])

        <button type="button" class="cv-sidebar-toggle" data-cv-sidebar-toggle
                aria-controls="cv-sidebar" aria-expanded="true" aria-label="Toggle navigation">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
    </div>

    <div class="cv-header__left">
        @if (count($shell->hotels) > 1)
            <div class="dropdown">
                <button type="button" class="cv-header-switcher" data-tour="hotel-switcher" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-hotel" aria-hidden="true"></i>
                    <span class="cv-header-switcher__text">
                        <span class="cv-header-switcher__label">Hotel</span>
                        <span class="cv-header-switcher__name">{{ $activeHotel['name'] }}</span>
                    </span>
                    <i class="fa-solid fa-angle-down small" aria-hidden="true"></i>
                    <span class="visually-hidden">Switch hotel</span>
                </button>
                <ul class="dropdown-menu">
                    <li class="cv-dropdown-header">Switch hotel</li>
                    @foreach ($shell->hotels as $hotel)
                        <li>
                            <a @class(['dropdown-item', 'active' => $hotel['active']]) href="{{ $hotel['url'] }}"
                               @if ($hotel['active']) aria-current="true" @endif>
                                <i class="fa-solid fa-hotel" aria-hidden="true"></i>{{ $hotel['name'] }}
                                @if ($hotel['needs_setup'] ?? false)
                                    <x-badge-status label="Finish setup" variant="warning" class="ms-1" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ Route::has('hotels.index') ? route('hotels.index') : '#' }}">
                            <i class="fa-solid fa-list" aria-hidden="true"></i>All hotels
                        </a>
                    </li>
                </ul>
            </div>
        @endif
    </div>

    <div class="cv-header__right">
        @if (config('citevanta.tours.enabled', true) && auth()->check())
            {{-- Help: replay the tour of this page, or restart all tours (see resources/js/tour.js). --}}
            <div class="dropdown">
                <button type="button" class="cv-header-icon" data-tour="help" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('tours.ui.help') }}">
                    <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="cv-dropdown-header">{{ __('tours.ui.help') }}</li>
                    <li>
                        <button type="button" class="dropdown-item" data-cv-tour-start @disabled(! ($tour ?? null))>
                            <i class="fa-solid fa-route" aria-hidden="true"></i>{{ ($tour ?? null) ? __('tours.ui.take_tour') : __('tours.ui.no_tour') }}
                        </button>
                    </li>
                    @if (app(\App\Services\Setup\SetupChecklist::class)->available(auth()->user(), request()->route()?->getName()))
                        <li>
                            <button type="button" class="dropdown-item" data-cv-checklist-restore>
                                <i class="fa-solid fa-list-check" aria-hidden="true"></i>{{ __('checklist.ui.help_entry') }}
                            </button>
                        </li>
                    @endif
                    <li>
                        <button type="button" class="dropdown-item" data-cv-tour-restart>
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>{{ __('tours.ui.restart_all') }}
                        </button>
                    </li>
                </ul>
            </div>
        @endif

        <div class="dropdown">
            <button type="button" class="cv-header-icon" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="Notifications{{ count($shell->notifications) ? ' ('.count($shell->notifications).' new)' : '' }}">
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                @if (count($shell->notifications))
                    <span class="cv-header-icon__badge" aria-hidden="true">{{ count($shell->notifications) }}</span>
                @endif
            </button>
            <div class="dropdown-menu dropdown-menu-end p-0 cv-notifications-menu">
                <div class="cv-dropdown-header">Notifications</div>
                @forelse ($shell->notifications as $notification)
                    <div class="cv-notification">
                        <span class="cv-notification__icon bg-{{ $notification['variant'] }}" aria-hidden="true">
                            <i class="fa-solid {{ $notification['icon'] }}"></i>
                        </span>
                        <div>
                            {{ $notification['text'] }}
                            <small>{{ $notification['time'] }}</small>
                        </div>
                    </div>
                @empty
                    <p class="px-3 py-3 mb-0 text-muted">You're all caught up.</p>
                @endforelse
            </div>
        </div>

        @if ($shell->user)
            <span class="cv-header__divider" aria-hidden="true"></span>

            <div class="dropdown">
                <button type="button" class="cv-userbox" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="cv-avatar" aria-hidden="true">{{ $shell->user['initials'] }}</span>
                    <span class="cv-userbox__text">
                        <span class="cv-userbox__name">{{ $shell->user['name'] }}</span>
                        <span class="cv-userbox__role">{{ $shell->user['role'] }}</span>
                    </span>
                    <i class="fa-solid fa-angle-down small" aria-hidden="true"></i>
                    <span class="visually-hidden">Open user menu</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="cv-dropdown-header">{{ $shell->user['name'] }}</li>
                    <li>
                        <a class="dropdown-item" href="{{ Route::has('settings.profile') ? route('settings.profile') : '#' }}">
                            <i class="fa-solid fa-user" aria-hidden="true"></i>Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ Route::has('settings.team') ? route('settings.team') : '#' }}">
                            <i class="fa-solid fa-users" aria-hidden="true"></i>Team settings
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        @if (Route::has('logout'))
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item">
                                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out
                                </button>
                            </form>
                        @else
                            <a class="dropdown-item" href="#"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
                        @endif
                    </li>
                </ul>
            </div>
        @endif
    </div>
</header>
