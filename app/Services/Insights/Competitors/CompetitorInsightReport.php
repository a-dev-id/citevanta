<?php

namespace App\Services\Insights\Competitors;

use App\Enums\HotelHasFeature;
use App\Models\CompetitorInsight;
use App\Models\ScanRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads competitor_insights for the dashboard: per prompt (Prompt results
 * accordion) and across all prompts of a scan ("Why competitors win" card).
 */
final class CompetitorInsightReport
{
    public const TOP = 8;

    /**
     * @return Collection<int, CompetitorInsight> keyed by prompt id
     */
    public function byPrompt(ScanRun $scan): Collection
    {
        return CompetitorInsight::query()->where('scan_run_id', $scan->id)->get()->keyBy('prompt_id');
    }

    /**
     * Reasons grouped by competitor, for one prompt's accordion panel.
     *
     * @return array<string, list<array{reason: string, quote: string, hotel_has: HotelHasFeature, fact_key: ?string}>>
     */
    public static function reasonsByCompetitor(CompetitorInsight $insight): array
    {
        return collect($insight->reasons ?? [])
            ->groupBy('competitor')
            ->map(fn (Collection $rows) => $rows->map(fn (array $row) => [
                'reason' => (string) $row['reason'],
                'quote' => (string) ($row['quote'] ?? ''),
                'hotel_has' => HotelHasFeature::tryFrom((string) ($row['hotel_has'] ?? '')) ?? HotelHasFeature::Unknown,
                'fact_key' => $row['fact_key'] ?? null,
            ])->values()->all())
            ->all();
    }

    /**
     * Across all prompts of a scan: the reasons competitors win most often,
     * and the sources that mention competitors but not the hotel.
     *
     * @return array{prompts: int, reasons: list<array{reason: string, competitors: list<string>, prompts: int, hotel_has: HotelHasFeature}>, sources: list<array{domain: string, competitors: list<string>, prompts: int, answers: int}>}
     */
    public function summary(ScanRun $scan): array
    {
        $insights = CompetitorInsight::query()->where('scan_run_id', $scan->id)->get();

        $reasons = $insights
            ->flatMap(fn (CompetitorInsight $insight) => collect($insight->reasons ?? [])->map(fn (array $row) => $row + ['prompt_id' => $insight->prompt_id]))
            ->groupBy(fn (array $row) => Str::slug((string) $row['reason']))
            ->map(function (Collection $rows) {
                $has = $rows->pluck('hotel_has')->map(fn ($value) => HotelHasFeature::tryFrom((string) $value) ?? HotelHasFeature::Unknown);

                return [
                    'reason' => (string) $rows->first()['reason'],
                    'competitors' => $rows->pluck('competitor')->unique()->values()->all(),
                    'prompts' => $rows->pluck('prompt_id')->unique()->count(),
                    // The most useful verdict wins: "you have this" is the quickest fix.
                    'hotel_has' => $has->contains(HotelHasFeature::HasIt) ? HotelHasFeature::HasIt
                        : ($has->contains(HotelHasFeature::LacksIt) ? HotelHasFeature::LacksIt : HotelHasFeature::Unknown),
                ];
            })
            ->sortBy([['prompts', 'desc'], ['reason', 'asc']])
            ->take(self::TOP)
            ->values()->all();

        $sources = $insights
            ->flatMap(fn (CompetitorInsight $insight) => collect($insight->sources ?? [])->map(fn (array $row) => $row + ['prompt_id' => $insight->prompt_id]))
            ->groupBy('domain')
            ->map(fn (Collection $rows, string $domain) => [
                'domain' => $domain,
                'competitors' => $rows->pluck('competitors')->flatten()->unique()->values()->all(),
                'prompts' => $rows->pluck('prompt_id')->unique()->count(),
                'answers' => (int) $rows->sum('answers'),
            ])
            ->sortBy([['prompts', 'desc'], ['answers', 'desc'], ['domain', 'asc']])
            ->take(self::TOP)
            ->values()->all();

        return ['prompts' => $insights->count(), 'reasons' => $reasons, 'sources' => $sources];
    }
}
