<?php

namespace App\Services\Budget;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Enums\ScanTrigger;
use App\Models\AiUsage;
use App\Models\ScanRun;
use App\Models\Team;
use Carbon\CarbonInterface;

/**
 * Spend per budget bucket this month (Asia/Makassar), caps, and the measured
 * averages used for estimates and Admin > System. Everything comes from
 * ai_usages (one row per real AI call).
 */
final class AiSpend
{
    public function monthStart(): CarbonInterface
    {
        return now((string) config('citevanta.budget.timezone', 'Asia/Makassar'))->startOfMonth()->utc();
    }

    public function monthKey(): string
    {
        return now((string) config('citevanta.budget.timezone', 'Asia/Makassar'))->format('Y-m');
    }

    public function spent(AiBucket $bucket): float
    {
        return (float) AiUsage::query()->where('billed_to_team', false)->where('bucket', $bucket->value)->where('created_at', '>=', $this->monthStart())->sum('cost_usd');
    }

    public function total(): float
    {
        return (float) AiUsage::query()->where('billed_to_team', false)->where('created_at', '>=', $this->monthStart())->sum('cost_usd');
    }

    public function totalCap(): float
    {
        return max(0, (float) config('citevanta.budget.total', 70));
    }

    /**
     * Audits, trials and admin have their own cap; paying customers get what the total leaves.
     */
    public function cap(AiBucket $bucket): float
    {
        $buckets = (array) config('citevanta.budget.buckets', []);

        if ($bucket === AiBucket::Paid) {
            return max(0, $this->totalCap() - array_sum(array_map('floatval', $buckets)));
        }

        return max(0, (float) ($buckets[$bucket->value] ?? 0));
    }

    public function teamSpent(Team $team): float
    {
        return (float) AiUsage::query()->where('billed_to_team', false)->where('team_id', $team->id)->where('created_at', '>=', $this->monthStart())->sum('cost_usd');
    }

    /**
     * Measured average cost of one call of this kind, or null with too few samples.
     */
    public function average(AiCallKind $kind, int $days = 30): ?float
    {
        $row = AiUsage::query()->where('kind', $kind->value)->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as calls, avg(cost_usd) as average')->toBase()->first();

        return (int) $row->calls >= (int) config('citevanta.budget.min_samples', 5) ? (float) $row->average : null;
    }

    /**
     * What the next call of this kind will probably cost, with the safety margin.
     */
    public function estimate(AiCallKind $kind): float
    {
        $base = $this->average($kind) ?? (float) config("citevanta.budget.default_estimates.{$kind->value}", 0.03);

        return round($base * (1 + (float) config('citevanta.budget.safety_margin', 0.5)), 6);
    }

    /**
     * One scan's likely cost: each planned call is an answer plus its analysis.
     */
    public function estimateCalls(int $calls): float
    {
        return round($calls * ($this->estimate(AiCallKind::Answer) + $this->estimate(AiCallKind::Analysis)), 4);
    }

    /**
     * Measured averages for Admin > System.
     *
     * @return array{answers: int, per_answer: ?float, searches_per_answer: ?float, per_audit: ?float, per_trial: ?float, per_scan: ?float}
     */
    public function measured(int $days): array
    {
        $since = now()->subDays($days);
        $base = fn () => AiUsage::query()->where('ai_usages.created_at', '>=', $since);

        $answers = (int) $base()->where('kind', AiCallKind::Answer->value)->count();
        $answerCost = (float) $base()->whereIn('kind', [AiCallKind::Answer->value, AiCallKind::Analysis->value])->sum('cost_usd');
        $searches = (float) $base()->where('kind', AiCallKind::Answer->value)->avg('web_searches');

        $auditRuns = ScanRun::query()->where('trigger', ScanTrigger::Audit->value)->select('id');
        $audits = $base()->whereIn('scan_run_id', $auditRuns)->selectRaw('count(distinct scan_run_id) as n, sum(cost_usd) as cost')->toBase()->first();
        $scans = $base()->whereNotNull('scan_run_id')->whereNotIn('scan_run_id', $auditRuns)->selectRaw('count(distinct scan_run_id) as n, sum(cost_usd) as cost')->toBase()->first();
        $trials = $base()->where('bucket', AiBucket::Trials->value)->whereNotNull('team_id')->selectRaw('count(distinct team_id) as n, sum(cost_usd) as cost')->toBase()->first();

        $average = fn ($row) => (int) $row->n > 0 ? round((float) $row->cost / (int) $row->n, 4) : null;

        return [
            'answers' => $answers,
            'per_answer' => $answers > 0 ? round($answerCost / $answers, 5) : null,
            'searches_per_answer' => $answers > 0 ? round($searches, 2) : null,
            'per_audit' => $average($audits),
            'per_trial' => $average($trials),
            'per_scan' => $average($scans),
        ];
    }
}
