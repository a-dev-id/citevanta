<?php

namespace App\Services\Navigation;

use App\Http\Controllers\HotelController;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Hotels\CurrentHotel;

/**
 * Data the app shell (header + sidebar) needs for the signed-in user.
 */
final readonly class ShellData
{
    /**
     * @param  array{name: string, initials: string, role: string}|null  $user
     * @param  list<array{name: string, url: string, active: bool, needs_setup?: bool}>  $hotels
     * @param  list<array{icon: string, variant: string, text: string, time: string}>  $notifications
     * @param  array<string, list<MenuItem>>  $menu
     */
    public function __construct(
        public ?array $user,
        public array $hotels,
        public array $notifications,
        public array $menu,
    ) {}

    public static function for(?User $user, ?string $activeMenu = null): self
    {
        $team = $user?->currentTeam;

        $current = $user !== null ? app(CurrentHotel::class)->get($user) : null;
        $sectionByRoute = array_flip(HotelController::SECTIONS) + ['hotels.scans.show' => 'show', 'hotels.citations.domain' => 'citations'];
        $section = $sectionByRoute[request()->route()?->getName()] ?? 'show';

        $hotels = $team === null ? [] : $team->hotels()->orderBy('name')->get(['id', 'name', 'setup_completed_at'])
            ->map(fn (Hotel $hotel) => [
                'name' => $hotel->name,
                'url' => route('hotels.switch', ['hotel' => $hotel, 'to' => $section]),
                'active' => $current?->id === $hotel->id,
                'needs_setup' => ! $hotel->isSetUp(),
            ])->all();

        return new self(
            user: $user === null ? null : [
                'name' => $user->name,
                'initials' => $user->initials(),
                'role' => $user->is_admin ? 'Administrator' : ($team !== null ? ($user->teamRole($team)?->label() ?? 'Member') : 'Member'),
            ],
            hotels: $hotels,
            notifications: [],
            menu: app(SidebarMenu::class)->sections($user, $activeMenu),
        );
    }
}
