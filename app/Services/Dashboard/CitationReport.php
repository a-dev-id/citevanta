<?php

namespace App\Services\Dashboard;

use App\Enums\ScanStatus;
use App\Models\Citation;
use App\Models\Hotel;
use App\Models\ScanRun;
use Illuminate\Support\Collection;

/**
 * Cited domains across a hotel's recent completed scans, and the drill-down
 * to the URLs and prompts behind one domain.
 */
final class CitationReport
{
    public const SCANS = 3;

    public function __construct(private readonly DomainClassifier $classifier) {}

    /**
     * @return Collection<int, ScanRun> most recent first
     */
    public function recentScans(Hotel $hotel): Collection
    {
        return $hotel->scanRuns()
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->limit(self::SCANS)
            ->get();
    }

    /**
     * @param  Collection<int, ScanRun>  $scans
     * @return list<array{domain: string, type: string, total: int, per_scan: array<int, int>}>
     */
    public function domains(Hotel $hotel, Collection $scans): array
    {
        if ($scans->isEmpty()) {
            return [];
        }

        $context = DomainContext::forHotel($hotel);

        return Citation::query()
            ->join('responses', 'responses.id', '=', 'citations.response_id')
            ->whereIn('responses.scan_run_id', $scans->pluck('id'))
            ->selectRaw('citations.domain, responses.scan_run_id, count(*) as citations')
            ->groupBy('citations.domain', 'responses.scan_run_id')
            ->get()
            ->groupBy('domain')
            ->map(fn (Collection $rows, string $domain) => [
                'domain' => $domain,
                'type' => $this->classifier->classify($domain, $context)->value,
                'total' => (int) $rows->sum('citations'),
                'per_scan' => $scans->mapWithKeys(fn (ScanRun $scan) => [
                    $scan->id => (int) $rows->firstWhere('scan_run_id', $scan->id)?->citations,
                ])->all(),
            ])
            ->sortBy([['total', 'desc'], ['domain', 'asc']])
            ->values()->all();
    }

    /**
     * Every URL of one domain cited in the given scans, with the prompts that led to it.
     *
     * @param  Collection<int, ScanRun>  $scans
     * @return list<array{url: string, title: ?string, citations: int, prompts: list<string>, scans: list<int>}>
     */
    public function urls(string $domain, Collection $scans): array
    {
        return Citation::query()
            ->with('response.prompt')
            ->where('domain', $domain)
            ->whereHas('response', fn ($query) => $query->whereIn('scan_run_id', $scans->pluck('id')))
            ->get()
            ->groupBy('url')
            ->map(fn (Collection $citations, string $url) => [
                'url' => $url,
                'title' => $citations->pluck('title')->filter()->first(),
                'citations' => $citations->count(),
                'prompts' => $citations->map(fn ($c) => $c->response?->prompt?->text)->filter()->unique()->values()->all(),
                'scans' => $citations->map(fn ($c) => $c->response?->scan_run_id)->filter()->unique()->sort()->values()->all(),
            ])
            ->sortByDesc('citations')
            ->values()->all();
    }
}
