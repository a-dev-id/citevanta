<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;
use App\Models\Citation;
use App\Models\ScanRun;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a scan's answers into stored results. Used by FinalizeScanRunJob,
 * scan:test and the stuck-scan cleanup, so every path gives the same numbers.
 *
 * Stores:
 *  - visibility_scores: per engine, overall (persona null) and per persona
 *  - competitor_scores: share of voice, the hotel itself has competitor_id null
 *  - scan_runs.citation_summary (JSON): domain counts and whether the hotel's
 *    own website was cited. JSON rather than a table because it is written
 *    once per scan and always read whole for that scan's dashboard; the raw
 *    citations stay in the citations table for any drill-down.
 *  - status, finished_at, answers_count, failed_count, cost_usd and cost_breakdown (see ScanCost).
 */
final class ScanFinalizer
{
    private const TOP_DOMAINS = 20;

    public function __construct(
        private readonly VisibilityReportBuilder $reports,
        private readonly ScanCost $costs,
    ) {}

    public function finalize(ScanRun $scanRun): ScanRun
    {
        $scanRun->loadMissing('hotel');
        $hotel = $scanRun->hotel;
        $competitors = $hotel->competitors()->withTrashed()->orderBy('id')->get(['id', 'name', 'deleted_at']);
        $activeNames = $competitors->whereNull('deleted_at')->pluck('name')->values()->all();

        $answers = $scanRun->responses()->count();
        $analyzed = $scanRun->responses()->whereHas('analysis')->count();
        $planned = $scanRun->plannedCalls();

        // Planned calls without an answer (failed or never ran) + answers that could not be analysed.
        $failed = $planned > 0
            ? max(0, $planned - $answers) + ($answers - $analyzed)
            : (int) $scanRun->failed_count;

        DB::transaction(function () use ($scanRun, $hotel, $competitors, $activeNames, $answers, $failed): void {
            $scanRun->visibilityScores()->delete();
            $scanRun->competitorScores()->delete();

            foreach ($this->enginesIn($scanRun) as $engine) {
                $report = $this->reports->build($scanRun, $activeNames, $engine);

                if ($report->totalAnswers === 0) {
                    continue;
                }

                $scanRun->visibilityScores()->create([
                    'hotel_id' => $hotel->id,
                    'engine' => $engine,
                    'persona' => null,
                    'mentions' => $report->mentions,
                    'answers' => $report->totalAnswers,
                    'visibility_pct' => $report->visibility,
                    'avg_position' => $report->averagePosition,
                    'positive' => $report->sentimentCounts['positive'] ?? 0,
                    'neutral' => $report->sentimentCounts['neutral'] ?? 0,
                    'negative' => $report->sentimentCounts['negative'] ?? 0,
                ]);

                foreach ($report->perPersona as $persona => $row) {
                    $scanRun->visibilityScores()->create([
                        'hotel_id' => $hotel->id,
                        'engine' => $engine,
                        'persona' => $persona,
                        'mentions' => $row['mentions'],
                        'answers' => $row['answers'],
                        'visibility_pct' => $row['visibility'],
                        'avg_position' => $row['avg_position'],
                        'positive' => $row['positive'],
                        'neutral' => $row['neutral'],
                        'negative' => $row['negative'],
                    ]);
                }
            }

            $overall = $this->reports->build($scanRun, $activeNames);
            $ids = $competitors->pluck('id', 'name');

            foreach ($overall->shareOfVoice as $row) {
                $scanRun->competitorScores()->create([
                    'competitor_id' => $row['is_target'] ? null : ($ids[$row['name']] ?? null),
                    'mentions' => $row['mentions'],
                    'visibility_pct' => $row['visibility'],
                    'share_of_voice_pct' => $row['share_of_voice'],
                ]);
            }

            $scanRun->update([
                'status' => match (true) {
                    $answers === 0 => ScanStatus::Failed,
                    $failed > 0 => ScanStatus::CompletedWithErrors,
                    default => ScanStatus::Completed,
                },
                'started_at' => $scanRun->started_at ?? now(),
                'finished_at' => now(),
                'answers_count' => $answers,
                'failed_count' => $failed,
                'citation_summary' => $this->citationSummary($scanRun),
                ...$this->costFields($scanRun),
            ]);
        });

        return $scanRun->fresh();
    }

    /**
     * @return list<string>
     */
    private function enginesIn(ScanRun $scanRun): array
    {
        $fromAnswers = $scanRun->responses()->distinct()->orderBy('engine')->pluck('engine')->all();

        return array_values(array_unique([...$scanRun->engineList(), ...$fromAnswers]));
    }

    /**
     * @return array{total_citations: int, own_domain: ?string, own_cited: bool, own_citations: int, domains: list<array{domain: string, citations: int, answers: int}>}
     */
    private function citationSummary(ScanRun $scanRun): array
    {
        $rows = Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->where('responses.scan_run_id', $scanRun->id)
            ->selectRaw('citations.domain, count(*) as citations, count(distinct citations.response_id) as answers')
            ->groupBy('citations.domain')
            ->orderByDesc('citations')
            ->orderBy('citations.domain')
            ->get();

        $ownDomain = self::domainOf($scanRun->hotel->website);
        $isOwn = fn (string $domain) => $ownDomain !== null && ($domain === $ownDomain || str_ends_with($domain, '.'.$ownDomain));
        $ownCitations = (int) $rows->filter(fn ($row) => $isOwn((string) $row->domain))->sum('citations');

        return [
            'total_citations' => (int) $rows->sum('citations'),
            'own_domain' => $ownDomain,
            'own_cited' => $ownCitations > 0,
            'own_citations' => $ownCitations,
            'domains' => $rows->take(self::TOP_DOMAINS)->map(fn ($row) => [
                'domain' => (string) $row->domain,
                'citations' => (int) $row->citations,
                'answers' => (int) $row->answers,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{cost_breakdown: array<string, float>, cost_usd: float}
     */
    private function costFields(ScanRun $scanRun): array
    {
        $breakdown = $this->costs->breakdown($scanRun);

        return ['cost_breakdown' => $breakdown, 'cost_usd' => round(array_sum($breakdown), 6)];
    }

    public static function domainOf(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return $host === '' ? null : (preg_replace('/^www\./', '', $host) ?: null);
    }
}
