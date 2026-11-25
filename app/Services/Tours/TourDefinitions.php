<?php

namespace App\Services\Tours;

/**
 * The guided tours of the customer app: on which pages (route names) each one runs, its version and its steps.
 *
 * A step has an id (its text is in lang/{locale}/tours.php under "{tour}.steps.{id}"), the CSS selector of the
 * element it highlights, and optionally:
 *  - "sidebar": the element is in the sidebar, so a narrow screen opens the drawer for it;
 *  - "plan": "private" (only teams on the Private plan) or "trial" (only teams on the trial plan, for steps
 *    about locked features).
 * A step whose element is not on the page (or is hidden) is skipped when the tour runs.
 *
 * Raise a tour's version when steps are added: everyone is offered it once more.
 */
final class TourDefinitions
{
    /**
     * @return array<string, array{version: int, routes: list<string>, plan?: string, steps: list<array{id: string, target: string, sidebar?: bool, plan?: string}>}>
     */
    public static function all(): array
    {
        return [
            'dashboard' => [
                'version' => 1,
                'routes' => ['hotels.show'],
                'steps' => [
                    ['id' => 'switcher', 'target' => '[data-tour="hotel-switcher"]'],
                    ['id' => 'score', 'target' => '[data-tour="visibility"]'],
                    ['id' => 'trend', 'target' => '[data-tour="trend"]'],
                    ['id' => 'last_scan', 'target' => '[data-tour="last-scan"]'],
                    ['id' => 'menu', 'target' => '#cv-sidebar', 'sidebar' => true],
                    ['id' => 'trial', 'target' => '[data-tour="trial-banner"]', 'plan' => 'trial'],
                    ['id' => 'help', 'target' => '[data-tour="help"]'],
                ],
            ],
            'recommendations' => [
                'version' => 1,
                'routes' => ['hotels.recommendations'],
                'steps' => [
                    ['id' => 'list', 'target' => '[data-tour="rec-list"]'],
                    ['id' => 'quick_wins', 'target' => '[data-tour="rec-quick-wins"]'],
                    ['id' => 'looks_done', 'target' => '#rec-status'],
                    ['id' => 'effort_impact', 'target' => '#rec-impact'],
                    ['id' => 'content', 'target' => '[data-tour="rec-first"]'],
                    ['id' => 'paste', 'target' => '[data-tour="rec-first"]'],
                    ['id' => 'locked', 'target' => '[data-tour="rec-locked"]', 'plan' => 'trial'],
                ],
            ],
            'prompts-scans' => [
                'version' => 1,
                'routes' => ['hotels.prompts', 'hotels.scans'],
                'steps' => [
                    ['id' => 'prompts', 'target' => '[data-tour="prompts"]'],
                    ['id' => 'regenerate', 'target' => '[data-tour="prompts-regenerate"]'],
                    ['id' => 'run_scan', 'target' => '[data-tour="nav-scans"]', 'sidebar' => true],
                    ['id' => 'history', 'target' => '[data-tour="scans-list"]'],
                    ['id' => 'progress', 'target' => '[data-tour="scans-table"]'],
                ],
            ],
            'reports' => [
                'version' => 1,
                'routes' => ['hotels.reports'],
                'plan' => 'private',
                'steps' => [
                    ['id' => 'date_picker', 'target' => '#range-button'],
                    ['id' => 'compare', 'target' => '#compare-form'],
                    ['id' => 'kpi', 'target' => '#ai-summary'],
                    ['id' => 'tables', 'target' => '[data-tour="report-table"]'],
                    ['id' => 'csv', 'target' => '[data-tour="report-csv"]'],
                    ['id' => 'searches', 'target' => '[data-tour="searches-by-date"]'],
                ],
            ],
            'settings' => [
                'version' => 1,
                'routes' => ['settings.ai-key', 'settings.google'],
                'plan' => 'private',
                'steps' => [
                    ['id' => 'ai_key', 'target' => '[data-tour="ai-key"]'],
                    ['id' => 'budget', 'target' => '[data-tour="ai-budget"]'],
                    ['id' => 'google_share', 'target' => '[data-tour="google-share"]'],
                    ['id' => 'google_pick', 'target' => '[data-tour="google-pick"]'],
                    ['id' => 'google_menu', 'target' => '[data-tour="nav-settings.google"]', 'sidebar' => true],
                ],
            ],
        ];
    }

    /** The tour that runs on a page, by route name. */
    public static function forRoute(?string $route): ?string
    {
        foreach (self::all() as $key => $tour) {
            if ($route !== null && in_array($route, $tour['routes'], true)) {
                return $key;
            }
        }

        return null;
    }
}
