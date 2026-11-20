<?php

namespace App\Services\Content;

use App\Enums\HotelHasFeature;
use App\Enums\IssueStatus;
use App\Enums\ScanStatus;
use App\Models\CompetitorInsight;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Services\Insights\FakeFeatureLexicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Part 1 of an "OTA listing update": a checklist of what to check or add in
 * the Booking.com extranet and Agoda YCS. Booking.com and Agoda write the
 * main description themselves from these settings, so this is where the
 * listing really changes. Built from the hotel's facts and the evidence (no
 * AI call): each item says where to go, what to set and why.
 */
final class OtaChecklistBuilder
{
    public const MAX_ITEMS = 12;

    /**
     * @param  array<string, string>  $facts  filled facts
     */
    public function build(Hotel $hotel, ?Recommendation $recommendation, array $facts): string
    {
        $reasons = $this->competitorReasons($hotel);
        $issues = $hotel->accuracyIssues()->where('status', IssueStatus::Open->value)->get()->keyBy(fn ($issue) => Str::slug($issue->fact_key));
        $prompt = collect($recommendation?->evidence ?? [])->firstWhere('type', 'prompt')['label'] ?? null;
        $items = [];

        foreach ($facts as $key => $value) {
            $group = $this->groupFor($key);

            if ($group === null) {
                continue;
            }

            $slug = Str::slug($key);
            $what = FakeFeatureLexicon::isNegative($value)
                ? "Make sure \"{$key}\" is not listed as available. Your facts: {$value}."
                : "Make sure \"{$key}\" is listed, with these details: {$value}.";
            $why = match (true) {
                $reasons->has($slug) => $reasons[$slug],
                $issues->has($slug) && ! $issues[$slug]->isMissingFact() => "AI said: “{$issues[$slug]->ai_claim}”, but your facts say: {$value}.",
                $prompt !== null => "Supports the prompt: “{$prompt}”.",
                default => 'AI reads these settings when it describes your hotel.',
            };

            $items[] = ['title' => $key, 'what' => $what, 'group' => $group, 'why' => $why, 'rank' => $reasons->has($slug) || $issues->has($slug) ? 0 : 1];
        }

        foreach ($this->missingTopics($hotel, $facts) as $topic => $why) {
            $group = $this->groupFor($topic) ?? (array) config('citevanta.content.ota_fields.facilities');
            $items[] = [
                'title' => Str::ucfirst($topic),
                'what' => '[add: '.Str::lower($topic).'] Tell us first whether you have this (add it to your '.config('app.name').' facts), then set it here.',
                'group' => $group,
                'why' => $why,
                'rank' => 0,
            ];
        }

        $items = collect($items)->sortBy('rank')->take(self::MAX_ITEMS)->values();

        $lines = ['PART 1: CHECKLIST FOR YOUR BOOKING.COM AND AGODA LISTINGS', ''];
        $lines[] = 'Booking.com and Agoda write your main description from these settings, so this is where your listing changes.';
        $lines[] = '';

        if ($items->isEmpty()) {
            $lines[] = '[add: facilities, room details and policies] Add your facts in '.config('app.name').' first, then create this again.';
        }

        foreach ($items as $i => $item) {
            $lines[] = ($i + 1).'. '.$item['title'];
            $lines[] = '   What to set: '.$item['what'];
            $lines[] = '   Booking.com: '.$item['group']['booking'];
            $lines[] = '   Agoda: '.$item['group']['agoda'];
            $lines[] = '   Why: '.$item['why'];
            $lines[] = '';
        }

        $lines[] = 'Note: Agoda may take up to 14 days to update your property description after you change these settings.';

        return implode("\n", $lines);
    }

    /**
     * @return array{keywords: list<string>, booking: string, agoda: string}|null
     */
    private function groupFor(string $factKey): ?array
    {
        $key = Str::lower($factKey);

        if (str_contains($key, 'price') || str_contains($key, 'rate')) {
            return null;
        }

        foreach ((array) config('citevanta.content.ota_fields', []) as $group) {
            foreach ($group['keywords'] as $keyword) {
                if (str_contains($key, $keyword)) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * "Kayon Jungle Resort was recommended for “kids club” (2 prompts)" by fact key slug, from the latest scan.
     *
     * @return Collection<string, string>
     */
    private function competitorReasons(Hotel $hotel): Collection
    {
        $scan = $hotel->scanRuns()->whereIn('status', ScanStatus::finishedWithResults())->orderByDesc('finished_at')->orderByDesc('id')->first();

        if ($scan === null) {
            return collect();
        }

        return CompetitorInsight::query()->where('scan_run_id', $scan->id)->get()
            ->flatMap(fn (CompetitorInsight $insight) => collect($insight->reasons)->map(fn ($r) => $r + ['prompt_id' => $insight->prompt_id]))
            ->filter(fn ($r) => ! empty($r['fact_key']))
            ->groupBy(fn ($r) => Str::slug($r['fact_key']))
            ->map(fn (Collection $rows) => $rows->pluck('competitor')->unique()->implode(' and ')
                .' was recommended for “'.$rows->first()['reason'].'” ('.$rows->pluck('prompt_id')->unique()->count().' '.Str::plural('prompt', $rows->pluck('prompt_id')->unique()->count()).').');
    }

    /**
     * Topics AI or competitors mention that the facts do not cover, with why.
     *
     * @param  array<string, string>  $facts
     * @return array<string, string>
     */
    private function missingTopics(Hotel $hotel, array $facts): array
    {
        $filled = collect($facts)->keys()->map(fn ($key) => Str::slug($key))->all();
        $topics = [];

        foreach ($hotel->accuracyIssues()->missingFacts()->where('status', IssueStatus::Open->value)->get() as $issue) {
            $topics[$issue->fact_key] = "AI mentions this: “{$issue->ai_claim}”.";
        }

        $scan = $hotel->scanRuns()->whereIn('status', ScanStatus::finishedWithResults())->orderByDesc('finished_at')->orderByDesc('id')->first();
        if ($scan !== null) {
            foreach (CompetitorInsight::query()->where('scan_run_id', $scan->id)->get() as $insight) {
                foreach ($insight->reasons as $reason) {
                    if (($reason['hotel_has'] ?? '') === HotelHasFeature::Unknown->value && ! str_starts_with($reason['reason'], 'mentioned by')) {
                        $topics[$reason['reason']] ??= "{$reason['competitor']} was recommended for “{$reason['reason']}”.";
                    }
                }
            }
        }

        return collect($topics)
            ->reject(fn ($why, $topic) => in_array(Str::slug($topic), $filled, true) || $this->groupFor($topic) === null)
            ->all();
    }
}
