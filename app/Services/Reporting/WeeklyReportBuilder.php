<?php

namespace App\Services\Reporting;

use App\Enums\IssueStatus;
use App\Enums\Persona;
use App\Enums\ScanStatus;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Recommendations\RecommendationReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The content of the weekly email: per hotel with a new completed scan since
 * the last report, the latest visibility and change, best and worst persona,
 * new and resolved accuracy issues, top 3 open recommendations and the
 * competitor that moved most.
 */
final class WeeklyReportBuilder
{
    public function __construct(private readonly RecommendationReport $recommendations) {}

    /**
     * @return list<array<string, mixed>> one entry per hotel with new data (empty: nothing to send)
     */
    public function build(Team $team, CarbonInterface $since): array
    {
        return $team->hotels()->where('is_audit', false)->orderBy('name')->get()
            ->map(fn (Hotel $hotel) => $this->forHotel($hotel, $since))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forHotel(Hotel $hotel, CarbonInterface $since): ?array
    {
        $latest = $this->finishedScans($hotel)->where('finished_at', '>', $since)->first();

        if ($latest === null) {
            return null;
        }

        $previous = $this->finishedScans($hotel)->where('finished_at', '<', $latest->finished_at)->first();
        $visibility = $latest->overallVisibility();
        $previousVisibility = $previous?->overallVisibility();
        [$best, $worst] = $this->personas($latest);

        return [
            'hotel' => $hotel->name,
            'visibility' => $visibility,
            'change' => $visibility !== null && $previousVisibility !== null ? round($visibility - $previousVisibility, 1) : null,
            'best_persona' => $best,
            'worst_persona' => $worst,
            'new_issues' => $hotel->accuracyIssues()->issues()->where('status', IssueStatus::Open->value)->where('created_at', '>', $since)->count(),
            'resolved_issues' => $hotel->accuracyIssues()->issues()->where('status', IssueStatus::Resolved->value)->where('resolved_at', '>', $since)->count(),
            'recommendations' => $this->recommendations->top($hotel, 3)->pluck('title')->all(),
            'competitor' => $previous !== null ? $this->competitorMove($latest, $previous) : null,
            'url' => route('hotels.show', $hotel),
        ];
    }

    /**
     * @return Builder<ScanRun>
     */
    private function finishedScans(Hotel $hotel)
    {
        return ScanRun::query()->where('hotel_id', $hotel->id)
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->whereNotNull('finished_at')
            ->with(['visibilityScores', 'competitorScores.competitor'])
            ->latest('finished_at');
    }

    /**
     * @return array{0: ?array{name: string, visibility: float}, 1: ?array{name: string, visibility: float}}
     */
    private function personas(ScanRun $scan): array
    {
        $rows = $scan->visibilityScores->whereNotNull('persona')->groupBy('persona')
            ->map(fn ($scores) => $scores->sum('answers') > 0 ? round($scores->sum('mentions') / $scores->sum('answers') * 100, 1) : null)
            ->filter(fn ($value) => $value !== null)
            ->sortDesc();

        if ($rows->count() < 2) {
            return [null, null];
        }

        $label = fn (string $persona) => ucfirst(Persona::tryFrom($persona)?->travellers() ?? $persona);

        return [
            ['name' => $label((string) $rows->keys()->first()), 'visibility' => (float) $rows->first()],
            ['name' => $label((string) $rows->keys()->last()), 'visibility' => (float) $rows->last()],
        ];
    }

    /**
     * @return array{name: string, change: float}|null
     */
    private function competitorMove(ScanRun $latest, ScanRun $previous): ?array
    {
        $before = $previous->competitorScores->whereNotNull('competitor_id')->keyBy('competitor_id');
        $move = null;

        foreach ($latest->competitorScores->whereNotNull('competitor_id') as $score) {
            if (! $before->has($score->competitor_id)) {
                continue;
            }

            $change = round($score->visibility_pct - $before[$score->competitor_id]->visibility_pct, 1);

            if ($change != 0 && ($move === null || abs($change) > abs($move['change']))) {
                $move = ['name' => (string) $score->competitor?->name, 'change' => $change];
            }
        }

        return $move;
    }
}
