<?php

namespace App\Services\Dashboard;

use App\Enums\DomainType;
use App\Enums\Persona;
use App\Enums\ScanStatus;
use App\Models\Citation;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ResponseAnalysis;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Models\User;
use App\Models\VisibilityScore;
use App\Services\Insights\Accuracy\AccuracyReport;
use App\Services\Insights\Competitors\CompetitorInsightReport;
use App\Services\Recommendations\RecommendationReport;
use App\Services\Reporting\VisibilityReportBuilder;
use App\Services\Scanning\ManualScanCheck;
use App\Services\Scanning\ManualScanGate;
use App\Services\Scanning\ScanProgress;
use App\Services\Scanning\ScanSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads stored scan results (visibility_scores, competitor_scores, citations)
 * for the hotel dashboard and the other hotel pages. Nothing is recalculated
 * from answers except per-prompt rows and link types, which have no table.
 */
final class DashboardService
{
    public function __construct(
        private readonly ScanSchedule $schedule,
        private readonly ManualScanGate $manualScans,
        private readonly DomainClassifier $classifier,
        private readonly AnswerHighlighter $highlighter,
        private readonly TrendService $trends,
        private readonly AccuracyReport $accuracy,
        private readonly CompetitorInsightReport $competitorInsights,
        private readonly RecommendationReport $recommendations,
        private readonly VisibilityReportBuilder $reports,
    ) {}

    /**
     * @param  ScanRun|null  $viewing  a past scan, or null for the latest completed scan
     * @param  string|null  $engine  one engine, or null for all engines
     * @param  string|null  $language  one prompt language, or null for all (only when the scan has several)
     */
    public function build(Hotel $hotel, User $user, ?ScanRun $viewing = null, ?string $engine = null, ?string $language = null): HotelDashboard
    {
        $latestCompleted = $this->latestCompleted($hotel);
        $scan = $viewing ?? $latestCompleted;
        $hasResults = $scan !== null && in_array($scan->status->value, ScanStatus::finishedWithResults(), true);

        $engines = $hasResults ? $this->engines($scan) : [];
        $engine = in_array($engine, $engines, true) ? $engine : null;
        $languages = $hasResults ? $this->languages($scan) : [];
        $language = count($languages) > 1 && in_array($language, $languages, true) ? $language : null;
        $previous = $hasResults ? $this->previousCompleted($scan) : null;

        $metrics = $hasResults ? $this->metrics($scan, $engine, $language) : null;
        $previousMetrics = $previous ? $this->metrics($previous, $engine, $language) : null;

        $activeScan = $hotel->scanRuns()->whereIn('status', ScanStatus::inProgress())->latest('id')->first();
        $manualScan = $this->manualScans->check($hotel, $user);
        $domainRows = $hasResults ? $this->domainCounts($scan, $engine, $language) : collect();
        $domainContext = DomainContext::forHotel($hotel);
        $domainRows = $domainRows->map(fn ($row) => $row + ['type' => $this->classifier->classify($row['domain'], $domainContext)->value]);

        return new HotelDashboard(
            hotel: $hotel,
            scan: $hasResults ? $scan : null,
            isLatest: $viewing === null || $viewing->is($latestCompleted),
            previous: $previous,
            failedScan: $this->failedScanToShow($hotel, $viewing, $latestCompleted),
            engines: $engines,
            engine: $engine,
            metrics: $metrics,
            changes: $this->changes($metrics, $previousMetrics),
            personas: $hasResults ? $this->personas($scan, $engine, $language) : [],
            languages: $languages,
            language: $language,
            byLanguage: $hasResults && count($languages) > 1 ? $this->byLanguage($scan, $engine) : [],
            competitors: $hasResults ? $this->competitors($scan) : [],
            domains: $domainRows->take(10)->values()->all(),
            ownDomain: $domainContext->ownDomain,
            ownCited: $domainRows->contains('type', DomainType::Direct->value),
            links: self::linkTypes($domainRows),
            prompts: $hasResults ? $this->withCompetitorReasons($scan, $this->promptResults($scan, $engine, $language)) : [],
            history: $hotel->scanRuns()->latest('id')->limit(10)->get(),
            activeScan: $activeScan,
            progress: $activeScan ? ScanProgress::for($activeScan) : null,
            manualScan: $manualScan,
            allowanceText: $this->allowanceText($manualScan),
            lastCompletedAt: $latestCompleted?->finished_at,
            nextScheduledAt: $this->schedule->nextScheduledAt($hotel),
            trends: $hasResults ? $this->trends($hotel, $scan, $engine, $language) : null,
            accuracy: [
                'enabled' => (bool) config('citevanta.insights.accuracy.enabled'),
                'issues' => $this->accuracy->topOpen($hotel, 5),
                'open' => $this->accuracy->openCount($hotel),
                'missing' => $this->accuracy->openMissingFactsCount($hotel),
            ],
            recommendations: [
                'top' => $this->recommendations->top($hotel, 3),
                'open' => $this->recommendations->openCount($hotel),
            ],
            competitorReasons: [
                'enabled' => (bool) config('citevanta.insights.competitor_reasons.enabled'),
                'summary' => $hasResults ? $this->competitorInsights->summary($scan) : null,
            ],
        );
    }

    /**
     * Add "why competitors were recommended instead" to each prompt row (null when none).
     *
     * @param  list<array<string, mixed>>  $prompts
     * @return list<array<string, mixed>>
     */
    private function withCompetitorReasons(ScanRun $scan, array $prompts): array
    {
        $insights = $this->competitorInsights->byPrompt($scan);

        return array_map(function (array $prompt) use ($insights) {
            $insight = $insights->get($prompt['id']);

            return $prompt + ['why' => $insight === null ? null : [
                'competitors' => $insight->competitors ?? [],
                'summary' => $insight->summary,
                'reasons' => CompetitorInsightReport::reasonsByCompetitor($insight),
                'sources' => $insight->sources ?? [],
            ]];
        }, $prompts);
    }

    /**
     * Trend charts up to the shown scan; null below 2 scans ("Trends appear after your second scan").
     *
     * @return array{scans: int, visibility: array<string, mixed>, share_of_voice: array<string, mixed>, sources: array<string, mixed>}|null
     */
    public function trends(Hotel $hotel, ScanRun $scan, ?string $engine = null, ?string $language = null): ?array
    {
        $scans = $this->trends->scans($hotel, $scan);

        if ($scans->count() < 2) {
            return null;
        }

        return [
            'scans' => $scans->count(),
            'visibility' => $this->trends->visibility($scans, $engine, $language),
            'share_of_voice' => $this->trends->shareOfVoice($hotel, $scans),
            'sources' => $this->trends->sources($hotel, $scans, $engine),
        ];
    }

    public function latestCompleted(Hotel $hotel): ?ScanRun
    {
        return $hotel->scanRuns()
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->first();
    }

    public function previousCompleted(ScanRun $scan): ?ScanRun
    {
        return ScanRun::query()
            ->where('hotel_id', $scan->hotel_id)
            ->whereKeyNot($scan->id)
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->where(fn ($query) => $query->where('finished_at', '<', $scan->finished_at)
                ->orWhere(fn ($same) => $same->where('finished_at', $scan->finished_at)->where('id', '<', $scan->id)))
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->first();
    }

    /**
     * Prompt languages answered in a scan, English first.
     *
     * @return list<string>
     */
    public function languages(ScanRun $scan): array
    {
        return Prompt::withTrashed()
            ->whereIn('id', $scan->responses()->select('prompt_id'))
            ->distinct()->pluck('language')
            ->sortBy(fn ($code) => $code === 'en' ? '' : $code)
            ->values()->all();
    }

    /**
     * "Visibility by language": answers, mentions and visibility per prompt language.
     *
     * @return list<array{language: string, name: string, answers: int, mentions: int, visibility: float}>
     */
    public function byLanguage(ScanRun $scan, ?string $engine = null): array
    {
        $names = (array) config('citevanta.languages', []);

        return ResponseAnalysis::query()
            ->join('responses', 'responses.id', '=', 'response_analyses.response_id')
            ->join('prompts', 'prompts.id', '=', 'responses.prompt_id')
            ->where('responses.scan_run_id', $scan->id)
            ->when($engine, fn ($q) => $q->where('responses.engine', $engine))
            ->selectRaw('prompts.language, count(*) as answers, sum(case when response_analyses.hotel_mentioned then 1 else 0 end) as mentions')
            ->groupBy('prompts.language')
            ->toBase()->get()
            ->map(fn ($row) => [
                'language' => (string) $row->language,
                'name' => $names[$row->language] ?? strtoupper((string) $row->language),
                'answers' => (int) $row->answers,
                'mentions' => (int) $row->mentions,
                'visibility' => VisibilityReportBuilder::percentage((int) $row->mentions, (int) $row->answers),
            ])
            ->sortBy(fn ($row) => $row['language'] === 'en' ? '' : $row['language'])
            ->values()->all();
    }

    /**
     * @return list<string>
     */
    public function engines(ScanRun $scan): array
    {
        return $scan->visibilityScores()->distinct()->orderBy('engine')->pluck('engine')->all();
    }

    public function metrics(ScanRun $scan, ?string $engine = null, ?string $language = null): ScanMetrics
    {
        if ($language !== null) {
            // Scores are stored per engine and persona, not per language: read the answers.
            $report = $this->reports->build($scan, [], $engine, $language);
            $positive = (int) ($report->sentimentCounts['positive'] ?? 0);

            return new ScanMetrics(
                answers: $report->totalAnswers,
                mentions: $report->mentions,
                visibility: $report->totalAnswers > 0 ? $report->visibility : null,
                averagePosition: $report->averagePosition,
                positive: $positive,
                neutral: (int) ($report->sentimentCounts['neutral'] ?? 0),
                negative: (int) ($report->sentimentCounts['negative'] ?? 0),
                positivePct: $report->mentions > 0 ? VisibilityReportBuilder::percentage($positive, $report->mentions) : null,
                citedSources: $this->domainCounts($scan, $engine, $language)->count(),
            );
        }

        $rows = $scan->visibilityScores()->whereNull('persona')->when($engine, fn ($q) => $q->where('engine', $engine))->get();
        $answers = (int) $rows->sum('answers');
        $mentions = (int) $rows->sum('mentions');
        $positive = (int) $rows->sum('positive');

        return new ScanMetrics(
            answers: $answers,
            mentions: $mentions,
            visibility: $answers > 0 ? VisibilityReportBuilder::percentage($mentions, $answers) : null,
            averagePosition: $this->weightedPosition($rows),
            positive: $positive,
            neutral: (int) $rows->sum('neutral'),
            negative: (int) $rows->sum('negative'),
            positivePct: $mentions > 0 ? VisibilityReportBuilder::percentage($positive, $mentions) : null,
            citedSources: $this->domainCounts($scan, $engine)->count(),
        );
    }

    /**
     * @return list<array{persona: string, answers: int, mentions: int, visibility: float}>
     */
    public function personas(ScanRun $scan, ?string $engine = null, ?string $language = null): array
    {
        if ($language !== null) {
            return collect($this->reports->build($scan, [], $engine, $language)->perPersona)
                ->map(fn (array $row, string $persona) => ['persona' => $persona, 'answers' => $row['answers'], 'mentions' => $row['mentions'], 'visibility' => $row['visibility']])
                ->values()->all();
        }

        $rows = $scan->visibilityScores()->whereNotNull('persona')->when($engine, fn ($q) => $q->where('engine', $engine))->get();
        $order = array_column(Persona::cases(), 'value');

        return $rows->groupBy('persona')
            ->map(fn (Collection $group, string $persona) => [
                'persona' => $persona,
                'answers' => (int) $group->sum('answers'),
                'mentions' => (int) $group->sum('mentions'),
                'visibility' => VisibilityReportBuilder::percentage((int) $group->sum('mentions'), (int) $group->sum('answers')),
            ])
            ->sortBy(fn (array $row) => array_search($row['persona'], $order, true))
            ->values()->all();
    }

    /**
     * Share of voice across all engines (competitor_scores has no engine split).
     *
     * @return list<array{competitor_id: ?int, name: string, is_self: bool, mentions: int, visibility: float, share: float}>
     */
    public function competitors(ScanRun $scan): array
    {
        return $scan->competitorScores()->with('competitor')->get()
            ->map(fn ($score) => [
                'competitor_id' => $score->competitor_id,
                'name' => $score->competitor_id === null ? $scan->hotel->name : ($score->competitor?->name ?? 'Removed competitor'),
                'is_self' => $score->competitor_id === null,
                'mentions' => $score->mentions,
                'visibility' => $score->visibility_pct,
                'share' => $score->share_of_voice_pct,
            ])
            ->sortBy([['mentions', 'desc'], ['is_self', 'desc']])
            ->values()->all();
    }

    /**
     * All cited domains of a scan with their citation counts, most cited first.
     *
     * @return Collection<int, array{domain: string, citations: int}>
     */
    public function domainCounts(ScanRun $scan, ?string $engine = null, ?string $language = null): Collection
    {
        return Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->where('responses.scan_run_id', $scan->id)
            ->when($engine, fn ($q) => $q->where('responses.engine', $engine))
            ->when($language, fn ($q) => $q->whereIn('responses.prompt_id', Prompt::withTrashed()->where('language', $language)->select('id')))
            ->selectRaw('citations.domain, count(*) as citations')
            ->groupBy('citations.domain')
            ->orderByDesc('citations')->orderBy('citations.domain')
            ->get()
            ->map(fn ($row) => ['domain' => (string) $row->domain, 'citations' => (int) $row->citations]);
    }

    /**
     * Citations per domain type ("Where AI gets its information"), with each
     * type's share of all citations. Every type is listed, also those with 0.
     *
     * @param  Collection<int, array{domain: string, citations: int, type: string}>  $domains
     * @return array{total: int, types: list<array{type: DomainType, citations: int, share: float}>}
     */
    public static function linkTypes(Collection $domains): array
    {
        $total = (int) $domains->sum('citations');

        return [
            'total' => $total,
            'types' => array_map(function (DomainType $type) use ($domains, $total): array {
                $citations = (int) $domains->where('type', $type->value)->sum('citations');

                return [
                    'type' => $type,
                    'citations' => $citations,
                    'share' => $total > 0 ? round($citations / $total * 100, 1) : 0.0,
                ];
            }, DomainType::cases()),
        ];
    }

    /**
     * Per prompt: visibility and every answer, highlighted, with citations.
     *
     * @return list<array<string, mixed>>
     */
    public function promptResults(ScanRun $scan, ?string $engine = null, ?string $language = null): array
    {
        $hotel = $scan->hotel;
        $competitors = $hotel->competitors()->withTrashed()->pluck('name')->all();
        $order = array_flip($scan->prompt_ids ?? []);

        return ScanResponse::query()
            ->where('scan_run_id', $scan->id)
            ->when($engine, fn ($q) => $q->where('engine', $engine))
            ->when($language, fn ($q) => $q->whereIn('prompt_id', Prompt::withTrashed()->where('language', $language)->select('id')))
            ->with(['prompt', 'analysis', 'citations'])
            ->orderBy('prompt_id')->orderBy('engine')->orderBy('run_number')
            ->get()
            ->groupBy('prompt_id')
            ->map(function (Collection $responses) use ($hotel, $competitors) {
                $prompt = $responses->first()->prompt;
                $analysed = $responses->filter(fn ($r) => $r->analysis !== null);
                $mentions = $analysed->filter(fn ($r) => $r->analysis->hotel_mentioned)->count();

                return [
                    'id' => $prompt->id,
                    'text' => $prompt->text,
                    'persona' => $prompt->persona->value,
                    'answers' => $analysed->count(),
                    'mentions' => $mentions,
                    'visibility' => VisibilityReportBuilder::percentage($mentions, $analysed->count()),
                    'responses' => $responses->map(fn (ScanResponse $response) => [
                        'id' => $response->id,
                        'engine' => $response->engine,
                        'run' => $response->run_number,
                        'sentiment' => $response->analysis?->sentiment,
                        'position' => $response->analysis?->position,
                        'html' => $this->highlighter->highlight($response->answer_text, $hotel->name, $hotel->city, $competitors),
                        'citations' => $response->citations->map(fn ($c) => ['url' => $c->url, 'domain' => $c->domain, 'title' => $c->title])->all(),
                    ])->all(),
                ];
            })
            ->sortBy(fn ($row) => $order[$row['id']] ?? PHP_INT_MAX)
            ->values()->all();
    }

    /**
     * Visibility of each prompt in the hotel's latest completed scan.
     *
     * @return array<int, float> prompt id => visibility %
     */
    public function promptVisibility(Hotel $hotel): array
    {
        $scan = $this->latestCompleted($hotel);

        if ($scan === null) {
            return [];
        }

        return ScanResponse::query()
            ->where('scan_run_id', $scan->id)
            ->join('response_analyses', 'response_analyses.response_id', '=', 'responses.id')
            ->selectRaw('responses.prompt_id, count(*) as answers, sum(case when response_analyses.hotel_mentioned then 1 else 0 end) as mentions')
            ->groupBy('responses.prompt_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->prompt_id => VisibilityReportBuilder::percentage((int) $row->mentions, (int) $row->answers)])
            ->all();
    }

    /**
     * Each competitor's numbers in the latest completed scan (key "self" = the hotel).
     *
     * @return array<int|string, array{mentions: int, visibility: float, share: float}>
     */
    public function competitorStats(Hotel $hotel): array
    {
        $scan = $this->latestCompleted($hotel);

        return $scan === null ? [] : collect($this->competitors($scan))
            ->mapWithKeys(fn ($row) => [$row['competitor_id'] ?? 'self' => [
                'mentions' => $row['mentions'], 'visibility' => $row['visibility'], 'share' => $row['share'],
            ]])->all();
    }

    /**
     * One line per hotel for the hotel list.
     *
     * @return array{visibility: ?float, last_scan_at: mixed, latest: ?ScanRun}
     */
    public function hotelSummary(Hotel $hotel): array
    {
        $completed = $this->latestCompleted($hotel);

        return [
            'visibility' => $completed ? $this->metrics($completed)->visibility : null,
            'last_scan_at' => $completed?->finished_at,
            'latest' => $hotel->scanRuns()->latest('id')->first(),
        ];
    }

    /**
     * "42%", "16.7%" or "—" for display.
     */
    public static function pct(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    public static function engineLabel(string $engine): string
    {
        return (string) config("citevanta.engine_labels.{$engine}", ucfirst($engine));
    }

    /**
     * @param  Collection<int, VisibilityScore>  $rows
     */
    private function weightedPosition(Collection $rows): ?float
    {
        $ranked = $rows->filter(fn ($row) => $row->avg_position !== null && $row->mentions > 0);
        $weight = $ranked->sum('mentions');

        return $weight > 0 ? round($ranked->sum(fn ($row) => $row->avg_position * $row->mentions) / $weight, 1) : null;
    }

    /**
     * @return array{visibility: ?float, position: ?float, positive: ?float, sources: ?int}
     */
    private function changes(?ScanMetrics $now, ?ScanMetrics $before): array
    {
        $diff = fn (?float $a, ?float $b) => $a !== null && $b !== null ? round($a - $b, 1) : null;

        return [
            'visibility' => $diff($now?->visibility, $before?->visibility),
            'position' => $diff($now?->averagePosition, $before?->averagePosition),
            'positive' => $diff($now?->positivePct, $before?->positivePct),
            'sources' => $now !== null && $before !== null ? $now->citedSources - $before->citedSources : null,
        ];
    }

    /**
     * The failed scan to explain: the one being viewed, or a failure newer
     * than the last completed scan.
     */
    private function failedScanToShow(Hotel $hotel, ?ScanRun $viewing, ?ScanRun $latestCompleted): ?ScanRun
    {
        if ($viewing !== null) {
            return $viewing->status === ScanStatus::Failed ? $viewing : null;
        }

        $latestFinished = $hotel->scanRuns()->whereNotIn('status', ScanStatus::inProgress())->latest('id')->first();

        return $latestFinished?->status === ScanStatus::Failed && ($latestCompleted === null || $latestFinished->id > $latestCompleted->id)
            ? $latestFinished
            : null;
    }

    private function allowanceText(ManualScanCheck $check): string
    {
        if ($check->limit === null) {
            return 'Admin: no manual scan limit';
        }

        $left = max(0, $check->limit - $check->used);

        return "{$left} of {$check->limit} manual ".Str::plural('scan', $check->limit).' left this month';
    }
}
