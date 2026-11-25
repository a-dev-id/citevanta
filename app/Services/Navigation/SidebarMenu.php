<?php

namespace App\Services\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar: one "Navigation" section for everyone, plus an
 * "Admin" section for internal admin users (users.is_admin).
 */
final class SidebarMenu
{
    private ?string $activeKey = null;

    /**
     * @param  string|null  $activeKey  force an item active (e.g. the design preview)
     * @return array<string, list<MenuItem>> section heading => items
     */
    public function sections(?User $user, ?string $activeKey = null): array
    {
        $this->activeKey = $activeKey;

        $sections = [
            'Navigation' => [
                $this->item('dashboard', 'Dashboard', 'fa-gauge', route: 'dashboard', activeOn: ['dashboard', 'hotels.show', 'hotels.scans.show']),
                $this->group('hotels', 'Hotels', 'fa-hotel', [
                    $this->item('hotels.index', 'All hotels', 'fa-list', route: 'hotels.index', activeOn: ['hotels.index', 'hotels.edit']),
                    $this->item('hotels.create', 'Add hotel', 'fa-plus', route: 'hotels.create', activeOn: ['hotels.create', 'hotels.setup']),
                ]),
                $this->item('prompts', 'Prompts', 'fa-comments', route: 'prompts.index', activeOn: ['prompts.*', 'hotels.prompts']),
                $this->item('competitors', 'Competitors', 'fa-chess-knight', route: 'competitors.index', activeOn: ['competitors.*', 'hotels.competitors']),
                $this->group('scans', 'Scans', 'fa-satellite-dish', [
                    $this->item('scans.index', 'Scan history', 'fa-clock-rotate-left', route: 'scans.index', activeOn: ['scans.*', 'hotels.scans']),
                ]),
                $this->item('citations', 'Citations', 'fa-link', route: 'citations.index', activeOn: ['citations.*', 'hotels.citations*']),
                $this->group('insights', 'Insights', 'fa-magnifying-glass-chart', [
                    $this->item('insights.accuracy', 'Accuracy', 'fa-spell-check', route: 'accuracy.index', activeOn: ['accuracy.*', 'hotels.accuracy']),
                ]),
                $this->item('recommendations', 'Recommendations', 'fa-lightbulb', route: 'recommendations.index', activeOn: ['recommendations.*', 'hotels.recommendations']),
                ...($user?->currentTeam?->isPrivate() ? [
                    $this->item('reports', 'Reports', 'fa-file-lines', route: 'reports.index', activeOn: ['reports.*', 'hotels.reports*']),
                ] : []),
                $this->group('settings', 'Settings', 'fa-gear', [
                    $this->item('settings.team', 'Team', 'fa-users', route: 'settings.team', activeOn: 'settings.team'),
                    $this->item('settings.profile', 'Profile', 'fa-user', route: 'settings.profile', activeOn: 'settings.profile'),
                    ...($user?->ownsTeam($user->currentTeam) ? [$this->item('settings.billing', 'Billing', 'fa-credit-card', route: 'settings.billing', activeOn: ['settings.billing', 'billing.*'])] : []),
                    ...($user?->ownsTeam($user->currentTeam) && $user->currentTeam->isPrivate() ? [
                        $this->item('settings.ai-key', 'AI key', 'fa-key', route: 'settings.ai-key', activeOn: 'settings.ai-key*'),
                        $this->item('settings.google', 'Connect Google', 'fa-chart-line', route: 'settings.google', activeOn: 'settings.google*'),
                        $this->item('settings.website-script', 'Website script', 'fa-code', route: 'settings.website-script', activeOn: 'settings.website-script*'),
                    ] : []),
                ]),
            ],
        ];

        if ($user?->is_admin) {
            $sections['Admin'] = [
                $this->item('admin.teams', 'Teams', 'fa-people-group', route: 'admin.teams', activeOn: 'admin.teams*'),
                $this->item('admin.hotels', 'Hotels', 'fa-building', route: 'admin.hotels', activeOn: 'admin.hotels*'),
                $this->item('admin.scan-runs', 'Scan runs', 'fa-list-check', route: 'admin.scan-runs', activeOn: 'admin.scan-runs*'),
                $this->item('admin.audits', 'Audits', 'fa-magnifying-glass', route: 'admin.audits', activeOn: 'admin.audits*'),
                $this->item('admin.billing', 'Billing', 'fa-file-invoice-dollar', route: 'admin.billing', activeOn: 'admin.billing*'),
                $this->item('admin.failed-jobs', 'Failed jobs', 'fa-triangle-exclamation', route: 'admin.failed-jobs', activeOn: 'admin.failed-jobs*'),
                $this->item('admin.integrations', 'Integrations', 'fa-plug', route: 'admin.integrations.google', activeOn: 'admin.integrations*'),
                $this->item('admin.system', 'System', 'fa-server', route: 'admin.system', activeOn: 'admin.system'),
            ];
        }

        return $sections;
    }

    /**
     * @param  string|null  $route  route name to link to; no link until the route exists
     * @param  string|list<string>|null  $activeOn  route name patterns that mark the item active
     */
    private function item(string $key, string $label, string $icon, ?string $route = null, string|array|null $activeOn = null, ?string $badge = null): MenuItem
    {
        return new MenuItem(
            key: $key,
            label: $label,
            icon: $icon,
            url: $route !== null && Route::has($route) ? route($route) : null,
            active: $this->activeKey !== null
                ? $this->activeKey === $key
                : $activeOn !== null && request()->routeIs($activeOn),
            badge: $badge,
        );
    }

    /**
     * @param  list<MenuItem>  $children
     */
    private function group(string $key, string $label, string $icon, array $children): MenuItem
    {
        return new MenuItem(
            key: $key,
            label: $label,
            icon: $icon,
            active: $this->activeKey === $key,
            children: $children,
        );
    }
}
