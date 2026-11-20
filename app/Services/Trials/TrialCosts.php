<?php

namespace App\Services\Trials;

use App\Models\AiCall;
use App\Models\ScanRun;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * What free trials cost in AI calls. A team's AI cost is its scans
 * (scan_runs.cost_usd: answers, analysis and the insight calls in ai_calls)
 * plus content generation (ai_calls without a scan). Scan answers are not
 * rows in ai_calls, so summing ai_calls alone would miss most of the cost.
 */
final class TrialCosts
{
    /**
     * AI cost of one team so far.
     */
    public function teamCost(Team $team): float
    {
        return $this->costs(collect([$team]))[$team->id] ?? 0.0;
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<int, float> team id => USD
     */
    public function costs(Collection $teams): array
    {
        $ids = $teams->pluck('id')->all();

        $scans = ScanRun::query()
            ->join('hotels', 'hotels.id', '=', 'scan_runs.hotel_id')
            ->whereIn('hotels.team_id', $ids)
            ->selectRaw('hotels.team_id as team_id, coalesce(sum(scan_runs.cost_usd), 0) as cost')
            ->groupBy('hotels.team_id')
            ->pluck('cost', 'team_id');

        $content = AiCall::query()->whereNull('scan_run_id')->whereIn('team_id', $ids)
            ->selectRaw('team_id, coalesce(sum(cost_usd), 0) as cost')
            ->groupBy('team_id')
            ->pluck('cost', 'team_id');

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => round((float) ($scans[$id] ?? 0) + (float) ($content[$id] ?? 0), 6)])->all();
    }
}
