<?php

namespace App\Services\Audits;

use App\Enums\DomainType;
use App\Models\Audit;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Dashboard\DomainClassifier;
use App\Services\Dashboard\DomainContext;
use App\Services\Reporting\VisibilityReportBuilder;
use App\Services\Scanning\ScanFinalizer;
use Illuminate\Support\Collection;

/**
 * Turns a finished audit scan into the public report: visibility, how many of
 * the questions mentioned the hotel, the hotels recommended instead (there is
 * no competitor list, so: the other hotels named most often), where the AI got
 * its information (domain types) and 2-3 insights. Insights are stored as
 * translation keys with numbers and shown in the audit's language.
 */
final class AuditReportBuilder
{
    public const TOP_HOTELS = 5;

    public function __construct(
        private readonly HotelNameMatcher $matcher,
        private readonly DomainClassifier $classifier,
    ) {}

    public function build(Audit $audit, ScanRun $scan): AuditReport
    {
        $responses = $scan->responses()->whereHas('analysis')->with(['analysis', 'citations'])->get();
        $questions = $responses->pluck('prompt_id')->unique()->count();
        $mentionedPrompts = $responses->filter(fn (ScanResponse $r) => $r->analysis->hotel_mentioned)->pluck('prompt_id')->unique()->count();
        $mentions = $responses->filter(fn (ScanResponse $r) => $r->analysis->hotel_mentioned)->count();

        $instead = $this->recommendedInstead($responses, $audit);
        $types = $this->domainTypes($responses, $audit);

        return new AuditReport(
            questions: $questions,
            questionsMentioned: $mentionedPrompts,
            visibility: $responses->isEmpty() ? null : VisibilityReportBuilder::percentage($mentions, $responses->count()),
            recommendedInstead: $instead,
            domainTypes: $types,
            insights: $this->insights($audit, $questions, $mentionedPrompts, $instead, $types),
        );
    }

    /**
     * Other hotels named in the answers, grouped by fuzzy name, most answers first.
     *
     * @param  Collection<int, ScanResponse>  $responses
     * @return list<array{name: string, answers: int}>
     */
    private function recommendedInstead(Collection $responses, Audit $audit): array
    {
        $groups = [];

        foreach ($responses as $response) {
            $seen = [];
            foreach ((array) ($response->analysis->other_hotels_mentioned ?? []) as $name) {
                $name = trim((string) $name);
                if ($name === '' || $this->matcher->matches($name, $audit->hotel_name, [$audit->city])) {
                    continue;
                }

                $index = null;
                foreach ($groups as $i => $group) {
                    if ($this->matcher->matches($group['name'], $name, [$audit->city])) {
                        $index = $i;
                        break;
                    }
                }

                if ($index === null) {
                    $groups[] = ['name' => $name, 'answers' => 0];
                    $index = array_key_last($groups);
                }

                if (! isset($seen[$index])) {
                    $groups[$index]['answers']++;
                    $seen[$index] = true;
                }
            }
        }

        return collect($groups)->sortBy([['answers', 'desc'], ['name', 'asc']])->take(self::TOP_HOTELS)->values()->all();
    }

    /**
     * @param  Collection<int, ScanResponse>  $responses
     * @return array<string, int>
     */
    private function domainTypes(Collection $responses, Audit $audit): array
    {
        $context = new DomainContext(ScanFinalizer::domainOf($audit->website), [], $audit->city);
        $counts = [];

        foreach ($responses as $response) {
            foreach ($response->citations as $citation) {
                $type = $this->classifier->classify((string) $citation->domain, $context)->value;
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  list<array{name: string, answers: int}>  $instead
     * @param  array<string, int>  $types
     * @return list<array{key: string, params: array<string, string|int>}>
     */
    private function insights(Audit $audit, int $questions, int $mentioned, array $instead, array $types): array
    {
        if ($questions === 0) {
            return [];
        }

        $insights = [[
            'key' => $mentioned === 0 ? 'not_mentioned' : 'mentioned',
            'params' => ['count' => $mentioned, 'total' => $questions],
        ]];

        if ($instead !== []) {
            $insights[] = ['key' => 'instead', 'params' => [
                'hotels' => implode(', ', array_column(array_slice($instead, 0, 2), 'name')),
                'count' => $instead[0]['answers'],
            ]];
        }

        $total = array_sum($types);
        if ($total > 0) {
            $own = $types[DomainType::Direct->value] ?? 0;
            $top = (string) array_key_first($types);

            $insights[] = match (true) {
                $audit->website === null => ['key' => 'no_website', 'params' => ['type' => $top, 'share' => (int) round($types[$top] / $total * 100)]],
                $own === 0 => ['key' => 'own_not_cited', 'params' => ['type' => $top, 'share' => (int) round($types[$top] / $total * 100)]],
                default => ['key' => 'own_cited', 'params' => ['count' => $own, 'total' => $total]],
            };
        }

        return $insights;
    }
}
