<?php

namespace App\Services\Insights\Accuracy;

use App\Enums\IssueStatus;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Models\ScanResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Reads accuracy issues for the dashboard card and the Accuracy page.
 */
final class AccuracyReport
{
    /**
     * Open wrong/outdated claims, most serious first.
     *
     * @return Collection<int, AccuracyIssue>
     */
    public function topOpen(Hotel $hotel, int $limit = 5): Collection
    {
        return $this->ordered($hotel->accuracyIssues()->issues()->where('status', IssueStatus::Open->value))
            ->limit($limit)
            ->get();
    }

    public function openCount(Hotel $hotel): int
    {
        return $hotel->accuracyIssues()->issues()->where('status', IssueStatus::Open->value)->count();
    }

    public function openMissingFactsCount(Hotel $hotel): int
    {
        return $hotel->accuracyIssues()->missingFacts()->where('status', IssueStatus::Open->value)->count();
    }

    /**
     * @param  array{status?: string, severity?: string, verdict?: string, engine?: string}  $filters
     * @return Collection<int, AccuracyIssue>
     */
    public function issues(Hotel $hotel, array $filters = []): Collection
    {
        $query = $hotel->accuracyIssues()->issues()->with(['dismissedBy', 'lastSeenScan', 'firstSeenScan']);

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['severity'] ?? '') !== '') {
            $query->where('severity', $filters['severity']);
        }

        if (($filters['verdict'] ?? '') !== '') {
            $query->where('verdict', $filters['verdict']);
        }

        $issues = $this->ordered($query)->get();

        if (($filters['engine'] ?? '') !== '') {
            $issues = $issues->filter(fn (AccuracyIssue $issue) => in_array($filters['engine'], $issue->engines ?? [], true))->values();
        }

        return $issues;
    }

    /**
     * @return Collection<int, AccuracyIssue>
     */
    public function missingFacts(Hotel $hotel, string $status = 'open'): Collection
    {
        return $hotel->accuracyIssues()->missingFacts()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('occurrences')->orderBy('fact_key')
            ->get();
    }

    /**
     * Engines that appear on any issue of the hotel (for the filter).
     *
     * @return list<string>
     */
    public function engines(Hotel $hotel): array
    {
        return $hotel->accuracyIssues()->pluck('engines')->flatten()->filter()->unique()->sort()->values()->all();
    }

    /**
     * Links to the example answers of each issue: issue id => list of [url, label].
     *
     * @param  Collection<int, AccuracyIssue>  $issues
     * @return array<int, list<array{url: string, label: string}>>
     */
    public function answerLinks(Hotel $hotel, Collection $issues): array
    {
        $ids = $issues->pluck('example_response_ids')->flatten()->filter()->unique()->values();
        $responses = ScanResponse::query()
            ->whereIn('id', $ids)
            ->whereHas('scanRun', fn ($query) => $query->where('hotel_id', $hotel->id))
            ->get(['id', 'scan_run_id', 'engine', 'run_number'])
            ->keyBy('id');

        return $issues->mapWithKeys(fn (AccuracyIssue $issue) => [
            $issue->id => collect($issue->example_response_ids ?? [])
                ->map(fn ($id) => $responses->get($id))
                ->filter()
                ->map(fn (ScanResponse $response) => [
                    'url' => route('hotels.scans.show', [$hotel, $response->scan_run_id]).'#answer-'.$response->id,
                    'label' => 'Scan #'.$response->scan_run_id.', run '.$response->run_number,
                ])
                ->values()->all(),
        ])->all();
    }

    /**
     * @param  Builder<AccuracyIssue>|HasMany<AccuracyIssue, Hotel>  $query
     * @return Builder<AccuracyIssue>|HasMany<AccuracyIssue, Hotel>
     */
    private function ordered($query)
    {
        return $query
            ->orderByRaw("case severity when 'high' then 0 else 1 end")
            ->orderByDesc('occurrences')
            ->orderByDesc('last_seen_scan_id')
            ->orderBy('id');
    }
}
