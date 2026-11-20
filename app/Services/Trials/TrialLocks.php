<?php

namespace App\Services\Trials;

use App\Models\Hotel;
use App\Services\Insights\Accuracy\AccuracyReport;

/**
 * Trial lite: the top 3 open recommendations and the top 3 open accuracy
 * issues are shown in full; the rest show only their title and category
 * ("Upgrade to unlock all N"), and their content cannot be generated.
 * Paid teams see everything (null = nothing locked).
 */
final class TrialLocks
{
    public function __construct(private readonly AccuracyReport $accuracy) {}

    /**
     * @return list<int>|null ids shown in full, or null when nothing is locked
     */
    public function unlockedRecommendations(Hotel $hotel): ?array
    {
        if (! $this->locks($hotel)) {
            return null;
        }

        return $hotel->recommendations()->open()->orderByDesc('priority')->orderBy('id')
            ->limit($this->count())->pluck('id')->all();
    }

    /**
     * @return list<int>|null
     */
    public function unlockedIssues(Hotel $hotel): ?array
    {
        return $this->locks($hotel) ? $this->accuracy->topOpen($hotel, $this->count())->pluck('id')->all() : null;
    }

    public function isRecommendationLocked(Hotel $hotel, int $recommendationId): bool
    {
        $unlocked = $this->unlockedRecommendations($hotel);

        return $unlocked !== null && ! in_array($recommendationId, $unlocked, true);
    }

    public static function message(int $total, string $what = 'recommendations'): string
    {
        return "Upgrade to unlock all {$total} {$what}";
    }

    private function locks(Hotel $hotel): bool
    {
        return $hotel->team?->isOnTrial() ?? false;
    }

    private function count(): int
    {
        return max(0, (int) config('citevanta.trial.unlocked_items', 3));
    }
}
