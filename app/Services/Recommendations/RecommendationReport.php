<?php

namespace App\Services\Recommendations;

use App\Enums\Effort;
use App\Enums\Impact;
use App\Enums\RecommendationStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use Illuminate\Support\Collection;

/**
 * Reads recommendations for the Recommendations page and the dashboard card.
 */
final class RecommendationReport
{
    public const SORTS = ['priority' => 'Priority', 'impact' => 'Impact', 'effort' => 'Effort (quickest first)', 'newest' => 'Newest'];

    /**
     * @param  array{category?: string, status?: string, impact?: string, effort?: string}  $filters  status: open (default), looks_done, all, or a status value
     * @return Collection<int, Recommendation>
     */
    public function list(Hotel $hotel, array $filters = [], string $sort = 'priority'): Collection
    {
        $status = $filters['status'] ?? 'open';

        $query = $hotel->recommendations()->with('statusChangedBy')
            ->when(($filters['category'] ?? '') !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when(($filters['impact'] ?? '') !== '', fn ($q) => $q->where('impact', $filters['impact']))
            ->when(($filters['effort'] ?? '') !== '', fn ($q) => $q->where('effort', $filters['effort']));

        match ($status) {
            'all' => null,
            'open' => $query->open(),
            'looks_done' => $query->open()->whereNotNull('looks_done_at'),
            default => $query->where('status', $status),
        };

        return $this->sorted($query->get(), $sort);
    }

    /**
     * High impact, 15 minutes or 1 hour, not done or dismissed.
     *
     * @return Collection<int, Recommendation>
     */
    public function quickWins(Hotel $hotel, int $limit = 4): Collection
    {
        return $hotel->recommendations()->open()
            ->where('impact', Impact::High->value)
            ->whereIn('effort', [Effort::Minutes->value, Effort::Hour->value])
            ->whereNull('looks_done_at')
            ->orderByDesc('priority')->orderBy('id')
            ->limit($limit)->get();
    }

    /**
     * @return Collection<int, Recommendation>
     */
    public function top(Hotel $hotel, int $limit = 3): Collection
    {
        return $hotel->recommendations()->open()->whereNull('looks_done_at')
            ->orderByDesc('priority')->orderBy('id')->limit($limit)->get();
    }

    public function openCount(Hotel $hotel): int
    {
        return $hotel->recommendations()->open()->count();
    }

    /**
     * @param  Collection<int, Recommendation>  $items
     * @return Collection<int, Recommendation>
     */
    private function sorted(Collection $items, string $sort): Collection
    {
        return (match ($sort) {
            'impact' => $items->sortBy([fn ($a, $b) => $b->impact->weight() <=> $a->impact->weight(), fn ($a, $b) => $b->priority <=> $a->priority]),
            'effort' => $items->sortBy([fn ($a, $b) => $a->effort->rank() <=> $b->effort->rank(), fn ($a, $b) => $b->priority <=> $a->priority]),
            'newest' => $items->sortBy([fn ($a, $b) => $b->created_at <=> $a->created_at, fn ($a, $b) => $b->id <=> $a->id]),
            default => $items->sortBy([fn ($a, $b) => $b->priority <=> $a->priority, fn ($a, $b) => $a->id <=> $b->id]),
        })->values();
    }

    /** @return list<RecommendationStatus> */
    public static function statuses(): array
    {
        return RecommendationStatus::cases();
    }
}
