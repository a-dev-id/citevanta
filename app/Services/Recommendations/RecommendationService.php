<?php

namespace App\Services\Recommendations;

use App\Enums\Persona;
use App\Enums\RecommendationStatus;
use App\Enums\ScanStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps a hotel's recommendations in step with its latest scan:
 *  - new drafts are added (status "new")
 *  - existing ones get fresh evidence, priority and last-seen scan; when the
 *    rule text changes it replaces the old (AI) text and is rewritten again
 *  - open ones whose evidence is gone are flagged "Looks done" for the team
 *    to confirm; they lose the flag if the evidence comes back
 * Keys are unique per hotel, so nothing is ever duplicated.
 */
final class RecommendationService
{
    public function __construct(private readonly RecommendationRules $rules) {}

    /**
     * @return array{created: int, updated: int, looks_done: int}
     */
    public function refresh(ScanRun $scan): array
    {
        $scan->loadMissing('hotel');
        $hotel = $scan->hotel;

        if (! in_array($scan->status->value, ScanStatus::finishedWithResults(), true) || ! $this->isLatestCompleted($scan)) {
            return ['created' => 0, 'updated' => 0, 'looks_done' => 0];
        }

        $drafts = collect($this->rules->build($scan))->keyBy('key');
        $counts = ['created' => 0, 'updated' => 0, 'looks_done' => 0];

        DB::transaction(function () use ($hotel, $scan, $drafts, &$counts): void {
            $existing = $hotel->recommendations()->lockForUpdate()->get()->keyBy('key');

            foreach ($drafts as $key => $draft) {
                $attributes = [
                    'category' => $draft->category,
                    'effort' => $draft->effort,
                    'impact' => $draft->impact,
                    'priority' => $draft->priority(),
                    'evidence' => $draft->evidence,
                    'metric' => $draft->metric,
                    'last_seen_scan_id' => $scan->id,
                    'looks_done_at' => null,
                ];
                $text = ['title' => Str::limit($draft->title, 255, ''), 'why' => $draft->why, 'steps' => $draft->steps, 'where' => Str::limit($draft->where, 255, ''), 'draft_hash' => $draft->hash(), 'ai_written' => false];

                $recommendation = $existing->get($key);

                if ($recommendation === null) {
                    $hotel->recommendations()->create(['key' => $key, 'status' => RecommendationStatus::New, 'first_seen_scan_id' => $scan->id] + $attributes + $text);
                    $counts['created']++;

                    continue;
                }

                $recommendation->fill($attributes);
                if ($recommendation->draft_hash !== $draft->hash()) {
                    $recommendation->fill($text);
                }
                $recommendation->save();
                $counts['updated']++;
            }

            foreach ($existing as $key => $recommendation) {
                if (! $drafts->has($key) && $recommendation->status->isOpen() && $recommendation->looks_done_at === null) {
                    $recommendation->update(['looks_done_at' => now()]);
                    $counts['looks_done']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Open recommendations whose text is still the rule text (for the AI writer).
     *
     * @return Collection<int, Recommendation>
     */
    public function toRewrite(Hotel $hotel, int $limit): Collection
    {
        return $hotel->recommendations()->open()->where('ai_written', false)->whereNull('looks_done_at')
            ->orderByDesc('priority')->orderBy('id')->limit($limit)->get();
    }

    public function setStatus(Recommendation $recommendation, RecommendationStatus $status, User $user, ?string $reason = null): void
    {
        $attributes = [
            'status' => $status,
            'status_changed_by' => $user->id,
            'status_changed_at' => now(),
            'dismissed_reason' => $status === RecommendationStatus::Dismissed ? Str::limit(trim((string) $reason), 500, '') : null,
            'looks_done_at' => $status === RecommendationStatus::Done ? null : $recommendation->looks_done_at,
        ];

        if ($status === RecommendationStatus::Done && $recommendation->status !== RecommendationStatus::Done) {
            $attributes['done_at'] = now();
            $attributes['done_baseline_scan_id'] = $this->latestCompleted($recommendation->hotel)?->id;
        } elseif ($status !== RecommendationStatus::Done) {
            $attributes['done_at'] = null;
            $attributes['done_baseline_scan_id'] = null;
        }

        $recommendation->update($attributes);
    }

    /**
     * "Measured impact" of a done recommendation: the related visibility
     * (persona or prompt) in the last scan before it was done vs the latest scan.
     *
     * @return array{before: ?float, after: ?float, change: ?float, label: string, note: ?string}|null
     */
    public function measuredImpact(Recommendation $recommendation): ?array
    {
        if ($recommendation->status !== RecommendationStatus::Done || empty($recommendation->metric)) {
            return null;
        }

        $metric = $recommendation->metric;
        $label = $metric['type'] === 'persona'
            ? 'Visibility for '.(Persona::tryFrom((string) $metric['value'])?->travellers() ?? $metric['value'])
            : 'Visibility for the related prompt';
        $before = $recommendation->doneBaselineScan;
        $after = $this->latestCompleted($recommendation->hotel);

        if ($before === null || $after === null || $after->is($before) || $after->finished_at <= $recommendation->done_at) {
            return ['before' => $before ? $this->visibility($before, $metric) : null, 'after' => null, 'change' => null, 'label' => $label,
                'note' => 'Not enough data yet: impact shows after the next completed scan.'];
        }

        $beforeValue = $this->visibility($before, $metric);
        $afterValue = $this->visibility($after, $metric);

        return [
            'before' => $beforeValue,
            'after' => $afterValue,
            'change' => $beforeValue !== null && $afterValue !== null ? round($afterValue - $beforeValue, 1) : null,
            'label' => $label,
            'note' => $beforeValue === null || $afterValue === null ? 'This prompt or persona was not in both scans.' : null,
        ];
    }

    /**
     * @param  array{type: string, value: string|int}  $metric
     */
    public function visibility(ScanRun $scan, array $metric): ?float
    {
        if ($metric['type'] === 'persona') {
            $rows = $scan->visibilityScores()->where('persona', (string) $metric['value'])->get();

            return $rows->sum('answers') > 0 ? VisibilityReportBuilder::percentage((int) $rows->sum('mentions'), (int) $rows->sum('answers')) : null;
        }

        $responses = $scan->responses()->where('prompt_id', (int) $metric['value'])->whereHas('analysis')->with('analysis')->get();

        return $responses->isEmpty() ? null
            : VisibilityReportBuilder::percentage($responses->filter(fn ($r) => $r->analysis->hotel_mentioned)->count(), $responses->count());
    }

    private function latestCompleted(Hotel $hotel): ?ScanRun
    {
        return $hotel->scanRuns()->whereIn('status', ScanStatus::finishedWithResults())
            ->orderByDesc('finished_at')->orderByDesc('id')->first();
    }

    private function isLatestCompleted(ScanRun $scan): bool
    {
        return $this->latestCompleted($scan->hotel)?->is($scan) ?? false;
    }
}
