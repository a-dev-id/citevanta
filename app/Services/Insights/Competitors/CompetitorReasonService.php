<?php

namespace App\Services\Insights\Competitors;

use App\Enums\DomainType;
use App\Enums\HotelHasFeature;
use App\Models\CompetitorInsight;
use App\Models\Prompt;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Dashboard\DomainClassifier;
use App\Services\Dashboard\DomainContext;
use App\Services\Insights\Accuracy\AccuracyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Why competitors win": picks the prompts where a tracked competitor beat
 * the hotel, builds the input for one AI call per prompt, and stores the
 * answer as a competitor_insights row.
 *
 * A competitor beats the hotel in an answer when it is mentioned and the
 * hotel is not, or when both are mentioned and the competitor comes first.
 * Prompts are ranked by those wins (there is no search-volume data), then by
 * competitor mentions, and capped at insights.competitor_reasons.max_per_scan.
 *
 * Sources that mention a competitor but not the hotel are computed here, not
 * by the AI: domains cited in answers that recommended a competitor without
 * mentioning the hotel (the hotel's and competitors' own sites excluded).
 */
final class CompetitorReasonService
{
    /** @var array<int, Collection<int, ScanResponse>> */
    private array $responses = [];

    public function __construct(
        private readonly HotelNameMatcher $matcher,
        private readonly DomainClassifier $classifier,
        private readonly AccuracyService $accuracy,
    ) {}

    /**
     * Prompts to explain, best first.
     *
     * @return Collection<int, array{prompt_id: int, wins: int, competitors: list<string>, mentions: int}>
     */
    public function candidates(ScanRun $scanRun): Collection
    {
        $hotel = $scanRun->hotel;

        return $this->responses($scanRun)
            ->groupBy('prompt_id')
            ->map(function (Collection $responses, int $promptId) use ($hotel) {
                $winners = [];
                $wins = 0;
                $mentions = 0;

                foreach ($responses as $response) {
                    $beaten = $this->competitorsAhead($response, $hotel->name, (string) $hotel->city);
                    $mentions += count($response->analysis->competitors_mentioned ?? []);

                    if ($beaten !== []) {
                        $wins++;
                        $winners = [...$winners, ...$beaten];
                    }
                }

                return ['prompt_id' => $promptId, 'wins' => $wins, 'competitors' => array_values(array_unique($winners)), 'mentions' => $mentions];
            })
            ->filter(fn (array $row) => $row['wins'] > 0)
            ->sortBy([['wins', 'desc'], ['mentions', 'desc'], ['prompt_id', 'asc']])
            ->take(max(0, (int) config('citevanta.insights.competitor_reasons.max_per_scan', 10)))
            ->values();
    }

    /**
     * Competitors (as configured) that beat the hotel in one answer.
     *
     * @return list<string>
     */
    public function competitorsAhead(ScanResponse $response, string $hotelName, string $city): array
    {
        $analysis = $response->analysis;
        $competitors = array_values((array) ($analysis?->competitors_mentioned ?? []));

        if ($analysis === null || $competitors === []) {
            return [];
        }

        if (! $analysis->hotel_mentioned) {
            return $competitors;
        }

        $hotelAt = $this->firstPosition((string) $response->answer_text, $hotelName, $city);

        return array_values(array_filter($competitors, function (string $name) use ($response, $city, $hotelAt): bool {
            $at = $this->firstPosition((string) $response->answer_text, $name, $city);

            return $at !== null && ($hotelAt === null || $at < $hotelAt);
        }));
    }

    /**
     * @param  list<string>  $competitors
     */
    public function inputFor(ScanRun $scanRun, Prompt $prompt, array $competitors): CompetitorReasonInput
    {
        $hotel = $scanRun->hotel;

        return new CompetitorReasonInput(
            hotelName: $hotel->name,
            city: (string) $hotel->city,
            prompt: (string) $prompt->text,
            facts: $this->accuracy->factsFor($hotel),
            competitors: $competitors,
            answers: $this->responses($scanRun)->where('prompt_id', $prompt->id)
                ->map(fn (ScanResponse $response) => [
                    'engine' => $response->engine,
                    'text' => (string) $response->answer_text,
                    'citations' => $response->citations->map(fn ($c) => ['domain' => (string) $c->domain, 'title' => $c->title])->values()->all(),
                ])->values()->all(),
        );
    }

    /**
     * Store one prompt's result. "Has it" / "lacks it" is kept only when the
     * reason points at a fact the hotel has filled in; otherwise unknown.
     *
     * @param  array{prompt_id: int, wins: int, competitors: list<string>, mentions: int}  $candidate
     */
    public function apply(ScanRun $scanRun, array $candidate, CompetitorReasonResult $result): CompetitorInsight
    {
        $factKeys = collect($this->accuracy->factsFor($scanRun->hotel))->keys()->mapWithKeys(fn ($key) => [Str::slug($key) => $key]);
        $allowed = collect($candidate['competitors']);

        $reasons = collect($result->reasons)
            ->map(function (CompetitorReason $reason) use ($factKeys, $allowed) {
                $competitor = $allowed->first(fn ($name) => $this->matcher->matches($name, $reason->competitor)) ?? $reason->competitor;
                $slug = $reason->factKey === null ? null : Str::slug($reason->factKey);
                $known = $slug !== null && $factKeys->has($slug);

                return [
                    'competitor' => $competitor,
                    'reason' => $reason->reason,
                    'quote' => $reason->quote,
                    'fact_key' => $known ? $factKeys[$slug] : null,
                    'hotel_has' => ($known ? $reason->hotelHas : HotelHasFeature::Unknown)->value,
                ];
            })
            ->values()->all();

        return CompetitorInsight::updateOrCreate(
            ['scan_run_id' => $scanRun->id, 'prompt_id' => $candidate['prompt_id']],
            [
                'hotel_id' => $scanRun->hotel_id,
                'competitors' => $candidate['competitors'],
                'reasons' => $reasons,
                'sources' => $this->sourcesWithoutHotel($scanRun, $candidate['prompt_id']),
                'summary' => $result->summary === '' ? null : Str::limit($result->summary, 1000, ''),
                'wins' => $candidate['wins'],
            ],
        );
    }

    /**
     * Domains cited in this prompt's answers that recommended a competitor
     * but did not mention the hotel.
     *
     * @return list<array{domain: string, competitors: list<string>, answers: int}>
     */
    public function sourcesWithoutHotel(ScanRun $scanRun, int $promptId): array
    {
        $context = DomainContext::forHotel($scanRun->hotel);
        $sources = [];

        foreach ($this->responses($scanRun)->where('prompt_id', $promptId) as $response) {
            if ($response->analysis->hotel_mentioned || ($response->analysis->competitors_mentioned ?? []) === []) {
                continue;
            }

            foreach ($response->citations->pluck('domain')->unique() as $domain) {
                $type = $this->classifier->classify((string) $domain, $context);

                if ($type === DomainType::Direct || $type === DomainType::Competitor) {
                    continue;
                }

                $sources[$domain] ??= ['domain' => (string) $domain, 'competitors' => [], 'answers' => 0];
                $sources[$domain]['competitors'] = array_values(array_unique([...$sources[$domain]['competitors'], ...$response->analysis->competitors_mentioned]));
                $sources[$domain]['answers']++;
            }
        }

        return collect($sources)->sortByDesc('answers')->values()->all();
    }

    /**
     * Analysed answers of a scan with their citations (memoised for this service instance).
     *
     * @return Collection<int, ScanResponse>
     */
    private function responses(ScanRun $scanRun): Collection
    {
        return $this->responses[$scanRun->id] ??= $scanRun->responses()
            ->whereHas('analysis')
            ->with(['analysis', 'citations'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Where a hotel's name first appears in an answer: its full name, or else
     * its first distinctive word ("Kayon" for "Kayon Jungle Resort").
     */
    private function firstPosition(string $text, string $name, string $city): ?int
    {
        $lower = Str::lower(Str::ascii($text));
        $full = mb_strpos($lower, Str::lower(Str::ascii($name)));

        if ($full !== false) {
            return $full;
        }

        $core = $this->matcher->tokens($name, $city !== '' ? [$city] : []);
        $word = collect($core)->first(fn ($token) => mb_strlen($token) >= 4) ?? ($core[0] ?? null);

        if ($word === null) {
            return null;
        }

        return preg_match('/\b'.preg_quote($word, '/').'\b/u', $lower, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : null;
    }
}
