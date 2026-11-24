<?php

namespace App\Services\Reports;

use App\Models\Hotel;

/**
 * Plain-language findings from the reports, each with a link to where the
 * hotel can act: its Recommendations (the AI visibility advice from scans),
 * or the tracking check. Only numbers that are in
 * the synced data are quoted.
 */
final class ReportFindings
{
    /** A landing page needs this many AI sessions before "no bookings" means something. */
    private const MIN_PAGE_SESSIONS = 10;

    /**
     * @return list<array{level: string, text: string, actions: list<array{label: string, url: string}>}>
     */
    public function forAiTraffic(Hotel $hotel, AiTrafficReport $traffic): array
    {
        $findings = [];
        $summary = $traffic->summary()['current'];
        $recommendations = ['label' => 'Open recommendations', 'url' => route('hotels.recommendations', $hotel)];

        if ($summary['all_sessions'] > 0 && $summary['ai_sessions'] === 0) {
            $findings[] = $this->finding('info', 'No visits from AI assistants in this period. They can only send visitors once they recommend and link to your hotel; the recommendations show what to improve first.', [$recommendations, ['label' => 'Check tracking', 'url' => route('settings.google')]]);
        }

        foreach ($traffic->byLandingPage() as $page) {
            if ($page['sessions'] >= self::MIN_PAGE_SESSIONS && $page['purchases'] === 0) {
                $findings[] = $this->finding('warning', "{$page['name']} had {$page['sessions']} visits from AI assistants but no bookings. Check that this page shows rates, what is included and a clear way to book direct.", [$recommendations]);

                break;
            }
        }

        if ($summary['ai_purchases'] > 0 && $summary['purchase_share'] > $summary['session_share']) {
            $findings[] = $this->finding('success', sprintf('Visitors from AI assistants are %.1f%% of your sessions but %.1f%% of your bookings: they book more often than your average visitor.', $summary['session_share'] * 100, $summary['purchase_share'] * 100), [$recommendations]);
        }

        return $findings;
    }

    /**
     * @return list<array{level: string, text: string, actions: list<array{label: string, url: string}>}>
     */
    public function forSearch(Hotel $hotel, SearchReport $search): array
    {
        $findings = [];
        $recommendations = ['label' => 'Open recommendations', 'url' => route('hotels.recommendations', $hotel)];

        if (($opportunity = $search->opportunities()[0] ?? null) !== null) {
            $page = $search->topPageFor($opportunity['name']);
            $findings[] = $this->finding('warning', sprintf('"%s" was shown %s times at position %s, but only %.1f%% clicked.%s Improve the title and description so more people click.', $opportunity['name'], number_format($opportunity['impressions']), $opportunity['position'], $opportunity['ctr'] * 100, $page ? " The page is {$page}." : ''), [$recommendations]);
        }

        foreach ($search->seasonalGroups() as $group) {
            if ($group['impressions'] > 0 && $group['name'] !== 'Months') {
                $findings[] = $this->finding('info', sprintf('People search for %s: %s impressions and %s clicks. Make sure your website has an offer page for that season.', $group['name'], number_format($group['impressions']), number_format($group['clicks'])), [$recommendations]);

                break;
            }
        }

        return $findings;
    }

    /**
     * @param  list<array{label: string, url: string}>  $actions
     * @return array{level: string, text: string, actions: list<array{label: string, url: string}>}
     */
    private function finding(string $level, string $text, array $actions): array
    {
        return ['level' => $level, 'text' => $text, 'actions' => $actions];
    }
}
