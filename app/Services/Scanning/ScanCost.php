<?php

namespace App\Services\Scanning;

use App\Enums\AiPurpose;
use App\Models\AiCall;
use App\Models\ResponseAnalysis;
use App\Models\ScanRun;

/**
 * A scan run's cost split by purpose:
 *  - scan: the assistant answers (tokens + web search)
 *  - analysis: reading each answer (analysis model)
 *  - accuracy, competitor_reasons, recommendations: calls after the scan (ai_calls)
 *  Content generation is not a scan cost (see Admin > Teams).
 * Stored as scan_runs.cost_breakdown, with the total in cost_usd.
 */
final class ScanCost
{
    public function __construct(private readonly CostCalculator $costs) {}

    /**
     * @return array<string, float> purpose value => USD, every purpose present
     */
    public function breakdown(ScanRun $scanRun): array
    {
        $breakdown = array_fill_keys(array_map(fn (AiPurpose $p) => $p->value, self::purposes()), 0.0);

        $byModel = $scanRun->responses()
            ->selectRaw('model, coalesce(sum(tokens_in),0) as tin, coalesce(sum(tokens_out),0) as tout, coalesce(sum(web_search_calls),0) as ws')
            ->groupBy('model')
            ->get();

        foreach ($byModel as $row) {
            $breakdown[AiPurpose::Scan->value] += $this->costs->tokens((string) ($row->model ?? $scanRun->model), (int) $row->tin, (int) $row->tout)
                + $this->costs->webSearch((int) $row->ws);
        }

        $analysis = ResponseAnalysis::query()
            ->join('responses', 'responses.id', '=', 'response_analyses.response_id')
            ->where('responses.scan_run_id', $scanRun->id)
            ->selectRaw('coalesce(sum(response_analyses.tokens_in),0) as tin, coalesce(sum(response_analyses.tokens_out),0) as tout')
            ->toBase()
            ->first();

        $breakdown[AiPurpose::Analysis->value] = $this->costs->tokens((string) config('citevanta.analysis_model'), (int) $analysis->tin, (int) $analysis->tout);

        AiCall::query()
            ->where('scan_run_id', $scanRun->id)
            ->whereIn('purpose', array_keys($breakdown))
            ->selectRaw('purpose, coalesce(sum(cost_usd),0) as cost')
            ->groupBy('purpose')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$breakdown): void {
                $breakdown[(string) $row->purpose] = ($breakdown[(string) $row->purpose] ?? 0.0) + (float) $row->cost;
            });

        return array_map(fn (float $cost) => round($cost, 6), $breakdown);
    }

    /**
     * Purposes caused by a scan. Content is a team cost (Admin > Teams) and audits
     * are lead costs (Admin > Audits); neither is part of a scan breakdown.
     *
     * @return list<AiPurpose>
     */
    public static function purposes(): array
    {
        return array_values(array_filter(AiPurpose::cases(), fn (AiPurpose $purpose) => ! in_array($purpose, [AiPurpose::Content, AiPurpose::Audit], true)));
    }

    /**
     * Recalculate and store the breakdown and total (after the scan, and after each insight call).
     */
    public function refresh(ScanRun $scanRun): void
    {
        $breakdown = $this->breakdown($scanRun);

        $scanRun->update(['cost_breakdown' => $breakdown, 'cost_usd' => round(array_sum($breakdown), 6)]);
    }
}
