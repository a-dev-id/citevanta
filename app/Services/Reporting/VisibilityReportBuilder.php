<?php

namespace App\Services\Reporting;

use App\Enums\Persona;
use App\Enums\Sentiment;
use App\Models\Citation;
use App\Models\ResponseAnalysis;
use App\Models\ScanRun;
use App\Services\Analysis\HotelNameMatcher;
use Illuminate\Support\Collection;

/**
 * Aggregates a scan run's analysed answers into a VisibilityReport.
 * Only answers that were analysed count towards percentages.
 */
final class VisibilityReportBuilder
{
    public function __construct(private readonly HotelNameMatcher $matcher) {}

    /** Percentage rounded to one decimal; 0 when there is nothing to divide by. */
    public static function percentage(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    /**
     * @param  list<string>  $competitors  competitor names to include in share of voice
     * @param  string|null  $engine  only answers from this engine (null = all engines)
     * @param  string|null  $language  only answers to prompts in this language (null = all)
     */
    public function build(ScanRun $scanRun, array $competitors = [], ?string $engine = null, ?string $language = null): VisibilityReport
    {
        $scanRun->loadMissing('hotel');

        $analyses = ResponseAnalysis::query()
            ->join('responses', 'responses.id', '=', 'response_analyses.response_id')
            ->join('prompts', 'prompts.id', '=', 'responses.prompt_id')
            ->where('responses.scan_run_id', $scanRun->id)
            ->when($engine, fn ($query) => $query->where('responses.engine', $engine))
            ->when($language, fn ($query) => $query->where('prompts.language', $language))
            ->get(['response_analyses.*', 'prompts.persona as prompt_persona']);

        $total = $analyses->count();
        $mentions = $analyses->where('hotel_mentioned', true)->count();

        $perPersona = [];
        foreach (Persona::cases() as $persona) {
            $group = $analyses->where('prompt_persona', $persona->value);
            if ($group->isEmpty()) {
                continue;
            }
            $hits = $group->where('hotel_mentioned', true)->count();
            $perPersona[$persona->value] = [
                'answers' => $group->count(),
                'mentions' => $hits,
                'visibility' => self::percentage($hits, $group->count()),
                'avg_position' => $this->averagePosition($group),
            ] + $this->sentimentCounts($group);
        }

        $averagePosition = $this->averagePosition($analyses);
        $sentimentCounts = $this->sentimentCounts($analyses);

        $shareOfVoice = $this->shareOfVoice($scanRun->hotel->name, $mentions, $competitors, $analyses, $total, $scanRun->hotel->city);

        $topDomains = Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->where('responses.scan_run_id', $scanRun->id)
            ->when($engine, fn ($query) => $query->where('responses.engine', $engine))
            ->selectRaw('citations.domain, count(*) as citations')
            ->groupBy('citations.domain')
            ->orderByDesc('citations')
            ->orderBy('citations.domain')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['domain' => (string) $row->domain, 'citations' => (int) $row->citations])
            ->all();

        $usage = $scanRun->responses()
            ->when($engine, fn ($query) => $query->where('engine', $engine))
            ->selectRaw('coalesce(sum(tokens_in),0) as tin, coalesce(sum(tokens_out),0) as tout, coalesce(sum(web_search_calls),0) as ws')
            ->toBase()
            ->first();

        return new VisibilityReport(
            hotelName: $scanRun->hotel->name,
            totalAnswers: $total,
            mentions: $mentions,
            visibility: self::percentage($mentions, $total),
            perPersona: $perPersona,
            averagePosition: $averagePosition,
            sentimentCounts: $sentimentCounts,
            shareOfVoice: $shareOfVoice,
            topDomains: $topDomains,
            tokensIn: (int) $usage->tin,
            tokensOut: (int) $usage->tout,
            webSearchCalls: (int) $usage->ws,
            costUsd: (float) $scanRun->cost_usd,
        );
    }

    /**
     * Average rank when the hotel was mentioned in a ranked list.
     *
     * @param  Collection<int, ResponseAnalysis>  $analyses
     */
    private function averagePosition($analyses): ?float
    {
        $positions = $analyses->where('hotel_mentioned', true)->pluck('position')->filter(fn ($p) => $p !== null);

        return $positions->isEmpty() ? null : round($positions->avg(), 1);
    }

    /**
     * @param  Collection<int, ResponseAnalysis>  $analyses
     * @return array<string, int> sentiment value => answers
     */
    private function sentimentCounts($analyses): array
    {
        $counts = [];
        foreach (Sentiment::cases() as $sentiment) {
            $counts[$sentiment->value] = $analyses->filter(fn ($a) => $a->sentiment === $sentiment)->count();
        }

        return $counts;
    }

    /**
     * Mentions of our hotel vs each competitor. share_of_voice = an entity's
     * mentions / all tracked mentions; visibility = mentions / answers.
     *
     * @param  list<string>  $competitors
     * @param  Collection<int, ResponseAnalysis>  $analyses
     * @return list<array{name: string, is_target: bool, mentions: int, visibility: float, share_of_voice: float}>
     */
    private function shareOfVoice(string $hotelName, int $hotelMentions, array $competitors, $analyses, int $total, string $city): array
    {
        $counts = [['name' => $hotelName, 'is_target' => true, 'mentions' => $hotelMentions]];

        foreach ($competitors as $competitor) {
            $counts[] = [
                'name' => $competitor,
                'is_target' => false,
                'mentions' => $analyses->filter(function (ResponseAnalysis $a) use ($competitor, $city) {
                    return $this->matcher->indexIn($a->competitors_mentioned ?? [], $competitor, [$city]) !== null;
                })->count(),
            ];
        }

        $allMentions = array_sum(array_column($counts, 'mentions'));

        $rows = array_map(fn ($row) => $row + [
            'visibility' => self::percentage($row['mentions'], $total),
            'share_of_voice' => self::percentage($row['mentions'], $allMentions),
        ], $counts);

        usort($rows, fn ($a, $b) => [$b['mentions'], $b['is_target']] <=> [$a['mentions'], $a['is_target']]);

        return $rows;
    }
}
