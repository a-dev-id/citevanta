<?php

namespace App\Services;

use App\Models\TestRun;
use Illuminate\Support\Collection;

class VisibilityMetrics
{
    public function __construct(private VisibilityAnalysis $analysis) {}

    public function runs(?int $batchId = null): Collection
    {
        $runs = TestRun::with(['response.citations', 'response.assessments.hotel', 'provider', 'batch'])->where('is_simulated', app(TenantContext::class)->organization()->is_test_fixture)->where('status', 'completed')->when($batchId !== null, fn ($q) => $q->where('test_batch_id', $batchId))->latest('executed_at')->get()->filter(fn ($run) => $run->response && $this->analysis->isValid($run->response))->values();
        foreach ($runs as $run) {
            $this->analysis->preview($run->response);
            $run->response->loadMissing(['assessments.hotel', 'citations']);
        }

        if ($batchId === null) {
            $runs = $runs->filter(fn ($run) => ! $run->batch?->pilot_key || app(PilotQuality::class)->isApproved($run))->values();
        }

        return $runs;
    }

    public function summary(Collection $runs): array
    {
        $total = $runs->count();
        $mentioned = 0;
        $recommended = 0;
        $cited = 0;
        $ranks = [];
        foreach ($runs as $run) {
            $nandini = $run->response->assessments->first(fn ($a) => $a->hotel->isMonitoredBrand());
            $mentioned += (int) ($nandini?->mentioned ?? false);
            $recommended += (int) ($nandini?->recommended ?? false);
            $cited += (int) $run->response->citations->contains('classification', 'nandini_official');
            if ($nandini?->recommended && $nandini->rank !== null) {
                $ranks[] = $nandini->rank;
            }
        }

        return ['total' => $total, 'mentioned' => $mentioned, 'recommended' => $recommended, 'cited' => $cited, 'mentionRate' => $this->rate($mentioned, $total), 'recommendationRate' => $this->rate($recommended, $total), 'citationRate' => $this->rate($cited, $total), 'averagePosition' => count($ranks) ? round(array_sum($ranks) / count($ranks), 2) : null, 'rankedTests' => count($ranks)];
    }

    public function hotels(Collection $runs): Collection
    {
        $rows = [];
        foreach ($runs as $run) {
            foreach ($run->response->assessments as $assessment) {
                if (! $assessment->mentioned) {
                    continue;
                }$id = $assessment->hotel_id;
                if (! isset($rows[$id])) {
                    $rows[$id] = ['hotel' => $assessment->hotel, 'mentions' => 0, 'recommendations' => 0, 'ranks' => [], 'citations' => 0, 'official_citations' => 0, 'tests' => []];
                }
                $row = &$rows[$id];
                $row['mentions']++;
                $row['recommendations'] += (int) $assessment->recommended;
                if ($assessment->recommended && $assessment->rank !== null) {
                    $row['ranks'][] = $assessment->rank;
                }
                $sources = $run->response->citations->whereIn('id', $assessment->supporting_citation_ids);
                $row['citations'] += $sources->pluck('normalized_url')->filter()->unique()->count();
                $row['official_citations'] += $sources->filter(fn ($c) => in_array($c->classification, ['nandini_official', 'competitor_official'], true) && $c->hotel_id === $id)->pluck('normalized_url')->unique()->count();
                $row['tests'][] = ['run' => $run, 'assessment' => $assessment];
                unset($row);
            }
        }

        return collect($rows)->map(function ($row) {
            $row['average_position'] = count($row['ranks']) ? round(array_sum($row['ranks']) / count($row['ranks']), 2) : null;

            return $row;
        })->sortByDesc('recommendations')->values();
    }

    public function opportunities(Collection $runs): Collection
    {
        return $runs->filter(fn ($run) => ! $run->response->assessments->contains(fn ($a) => $a->hotel->isMonitoredBrand() && $a->recommended))->map(function ($run) {
            $competitors = $run->response->assessments->filter(fn ($a) => ! $a->hotel->isMonitoredBrand() && $a->recommended)->map(fn ($a) => ['assessment' => $a, 'official' => $run->response->citations->whereIn('id', $a->supporting_citation_ids)->contains(fn ($c) => $c->classification === 'competitor_official' && $c->hotel_id === $a->hotel_id)]);
            $pages = collect(app(CurrentWorkspace::class)->verifiedPages())->filter(fn ($page) => collect($page['keywords'])->contains(fn ($word) => str_contains(mb_strtolower($run->prompt_text), $word)));

            return ['run' => $run, 'competitors' => $competitors, 'pages' => $pages];
        })->values();
    }

    private function rate(int $count, int $total): ?float
    {
        return $total ? round($count / $total * 100, 1) : null;
    }
}
