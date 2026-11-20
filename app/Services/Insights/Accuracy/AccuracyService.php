<?php

namespace App\Services\Insights\Accuracy;

use App\Enums\AccuracyVerdict;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\ScanStatus;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns checked claims into accuracy_issues and keeps them current:
 *  - wrong / outdated claims become issues; not_in_facts claims become
 *    "missing facts"; correct claims are not stored
 *  - the same claim (fact key + claimed value) across answers and scans
 *    updates one row (occurrences, engines, example answers, last seen)
 *  - an open issue not seen in the next 2 completed scans is resolved
 *    automatically ("Looks fixed"); a missing fact is resolved as soon as the
 *    hotel enters that fact
 *  - a resolved issue seen again is reopened; a dismissed one stays dismissed
 */
final class AccuracyService
{
    public const SCANS_TO_RESOLVE = 2;

    public const MAX_EXAMPLES = 5;

    /**
     * Facts sent to the checker: only facts with a value.
     *
     * @return array<string, string>
     */
    public function factsFor(Hotel $hotel): array
    {
        return $hotel->facts()->orderBy('position')->get(['key', 'value'])
            ->filter(fn ($fact) => trim((string) $fact->value) !== '')
            ->mapWithKeys(fn ($fact) => [(string) $fact->key => trim((string) $fact->value)])
            ->all();
    }

    public function inputFor(ScanResponse $response): AccuracyInput
    {
        $hotel = $response->scanRun->hotel;

        return new AccuracyInput($hotel->name, (string) $hotel->city, $this->factsFor($hotel), (string) $response->answer_text);
    }

    /**
     * Store the claims of one checked answer. The verdict is re-checked here:
     * a claim about a fact the hotel has not entered is always not_in_facts.
     */
    public function apply(ScanResponse $response, AccuracyResult $result): void
    {
        $scanRun = $response->scanRun;
        $facts = collect($this->factsFor($scanRun->hotel));
        $factsByKey = $facts->mapWithKeys(fn ($value, $key) => [self::slug($key) => [$key, $value]]);

        foreach ($result->claims as $claim) {
            [$factKey, $trueValue] = $factsByKey->get(self::slug($claim->factKey), [$claim->factKey, null]);
            $verdict = $trueValue === null ? AccuracyVerdict::NotInFacts : $claim->verdict;

            if ($verdict === AccuracyVerdict::Correct) {
                continue;
            }

            $this->upsert($scanRun, $response, $claim, $factKey, $trueValue, $verdict);
        }
    }

    public static function claimKey(string $factKey, string $aiValue, AccuracyVerdict $verdict): string
    {
        return $verdict === AccuracyVerdict::NotInFacts
            ? Str::limit('missing|'.self::slug($factKey), 191, '')
            : Str::limit('issue|'.self::slug($factKey).'|'.self::slug($aiValue), 191, '');
    }

    public static function severityFor(string $factKey, string $claim): IssueSeverity
    {
        $text = Str::lower($factKey.' '.$claim);

        foreach ((array) config('citevanta.insights.accuracy.high_severity_words', []) as $word) {
            if (str_contains($text, Str::lower((string) $word))) {
                return IssueSeverity::High;
            }
        }

        return IssueSeverity::Medium;
    }

    /**
     * After a scan's checks: resolve open issues not seen in the last 2
     * completed scans, and missing facts the hotel has since entered.
     */
    public function resolveStale(Hotel $hotel, ScanRun $current): int
    {
        $resolved = 0;
        $filledFacts = collect($this->factsFor($hotel))->keys()->map(fn ($key) => self::slug($key))->all();
        $completed = $hotel->scanRuns()
            ->whereIn('status', ScanStatus::finishedWithResults())
            ->where('id', '<=', $current->id)
            ->orderBy('finished_at')->orderBy('id')
            ->pluck('id')->values();

        $hotel->accuracyIssues()->where('status', IssueStatus::Open->value)->get()
            ->each(function (AccuracyIssue $issue) use ($completed, $filledFacts, &$resolved): void {
                $position = $completed->search($issue->last_seen_scan_id);
                $scansSince = $position === false ? 0 : $completed->count() - 1 - $position;
                $factEntered = $issue->isMissingFact() && in_array(self::slug($issue->fact_key), $filledFacts, true);

                if ($scansSince >= self::SCANS_TO_RESOLVE || $factEntered) {
                    $issue->update(['status' => IssueStatus::Resolved, 'auto_resolved' => true, 'resolved_at' => now()]);
                    $resolved++;
                }
            });

        return $resolved;
    }

    public function dismiss(AccuracyIssue $issue, User $user, string $reason): void
    {
        $issue->update([
            'status' => IssueStatus::Dismissed,
            'dismissed_reason' => Str::limit(trim($reason), 500, ''),
            'dismissed_by' => $user->id,
            'dismissed_at' => now(),
        ]);
    }

    public function reopen(AccuracyIssue $issue): void
    {
        $issue->update([
            'status' => IssueStatus::Open,
            'auto_resolved' => false,
            'resolved_at' => null,
            'dismissed_reason' => null,
            'dismissed_by' => null,
            'dismissed_at' => null,
        ]);
    }

    private function upsert(ScanRun $scanRun, ScanResponse $response, AccuracyClaim $claim, string $factKey, ?string $trueValue, AccuracyVerdict $verdict): void
    {
        $key = self::claimKey($factKey, $claim->aiValue !== '' ? $claim->aiValue : $claim->aiClaim, $verdict);

        DB::transaction(function () use ($scanRun, $response, $claim, $factKey, $trueValue, $verdict, $key): void {
            $issue = AccuracyIssue::query()
                ->where('hotel_id', $scanRun->hotel_id)
                ->where('claim_key', $key)
                ->lockForUpdate()
                ->first();

            if ($issue === null) {
                AccuracyIssue::create([
                    'hotel_id' => $scanRun->hotel_id,
                    'claim_key' => $key,
                    'fact_key' => Str::limit($factKey, 100, ''),
                    'ai_claim' => Str::limit($claim->aiClaim, 500, ''),
                    'true_value' => $trueValue === null ? null : Str::limit($trueValue, 1000, ''),
                    'verdict' => $verdict,
                    'severity' => self::severityFor($factKey, $claim->aiClaim),
                    'quote' => $claim->quote === '' ? null : Str::limit($claim->quote, 500, ''),
                    'first_seen_scan_id' => $scanRun->id,
                    'last_seen_scan_id' => $scanRun->id,
                    'occurrences' => 1,
                    'engines' => [$response->engine],
                    'example_response_ids' => [$response->id],
                    'status' => IssueStatus::Open,
                ]);

                return;
            }

            $examples = array_values(array_unique([$response->id, ...($issue->example_response_ids ?? [])]));

            $issue->fill([
                'ai_claim' => Str::limit($claim->aiClaim, 500, ''),
                'true_value' => $trueValue === null ? null : Str::limit($trueValue, 1000, ''),
                'verdict' => $verdict,
                'quote' => $claim->quote === '' ? $issue->quote : Str::limit($claim->quote, 500, ''),
                'last_seen_scan_id' => max((int) $issue->last_seen_scan_id, $scanRun->id),
                'occurrences' => $issue->occurrences + 1,
                'engines' => array_values(array_unique([...($issue->engines ?? []), $response->engine])),
                'example_response_ids' => array_slice($examples, 0, self::MAX_EXAMPLES),
            ]);

            if ($issue->status === IssueStatus::Resolved) {
                $issue->fill(['status' => IssueStatus::Open, 'auto_resolved' => false, 'resolved_at' => null]);
            }

            $issue->save();
        });
    }

    private static function slug(string $value): string
    {
        return Str::slug(Str::lower(trim($value)));
    }
}
