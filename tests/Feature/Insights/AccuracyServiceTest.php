<?php

namespace Tests\Feature\Insights;

use App\Enums\AccuracyVerdict;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Insights\Accuracy\AccuracyClaim;
use App\Services\Insights\Accuracy\AccuracyResult;
use App\Services\Insights\Accuracy\AccuracyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccuracyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function hotelWithFacts(): Hotel
    {
        $hotel = Hotel::factory()->create(['name' => 'Alaya Resort Ubud', 'city' => 'Ubud']);
        $hotel->facts()->create(['key' => 'Kids club', 'value' => 'No kids club', 'position' => 1]);
        $hotel->facts()->create(['key' => 'Swimming pool', 'value' => 'Yes, infinity pool', 'position' => 2]);
        $hotel->facts()->create(['key' => 'Spa', 'value' => '', 'position' => 3]);

        return $hotel;
    }

    private function scan(Hotel $hotel, int $daysAgo = 0): ScanRun
    {
        return ScanRun::factory()->for($hotel)->create(['finished_at' => now()->subDays($daysAgo)]);
    }

    private function answer(ScanRun $scan, string $engine = 'openai'): ScanResponse
    {
        return ScanResponse::factory()->for($scan, 'scanRun')->create(['engine' => $engine]);
    }

    private function claim(string $factKey, string $value, AccuracyVerdict $verdict): AccuracyClaim
    {
        return new AccuracyClaim($factKey, "Alaya {$value}.", $value, null, $verdict, "Alaya {$value} for families.");
    }

    private function apply(ScanResponse $response, AccuracyClaim ...$claims): void
    {
        app(AccuracyService::class)->apply($response, new AccuracyResult($claims, 100, 50, 'gpt-5-mini'));
    }

    public function test_wrong_claims_become_issues_with_the_true_value_and_correct_claims_are_not_stored(): void
    {
        $hotel = $this->hotelWithFacts();
        $response = $this->answer($this->scan($hotel));

        $this->apply($response,
            $this->claim('kids club', 'has a kids club', AccuracyVerdict::Wrong),
            $this->claim('Swimming pool', 'has a pool', AccuracyVerdict::Correct),
        );

        $issue = $hotel->accuracyIssues()->sole();
        $this->assertSame(['Kids club', 'No kids club', AccuracyVerdict::Wrong, IssueSeverity::High, IssueStatus::Open],
            [$issue->fact_key, $issue->true_value, $issue->verdict, $issue->severity, $issue->status]);
        $this->assertSame([$response->id], $issue->example_response_ids);
    }

    public function test_a_claim_about_a_fact_the_hotel_has_not_entered_is_a_missing_fact_whatever_the_ai_said(): void
    {
        $hotel = $this->hotelWithFacts();
        $response = $this->answer($this->scan($hotel));

        $this->apply($response,
            $this->claim('Spa', 'has a spa', AccuracyVerdict::Wrong),
            $this->claim('Airport transfer', 'free airport transfer', AccuracyVerdict::Correct),
        );

        $this->assertSame(
            ['Airport transfer' => AccuracyVerdict::NotInFacts, 'Spa' => AccuracyVerdict::NotInFacts],
            $hotel->accuracyIssues()->orderBy('fact_key')->get()->mapWithKeys(fn ($i) => [$i->fact_key => $i->verdict])->all(),
        );
        $this->assertNull($hotel->accuracyIssues()->first()->true_value);
    }

    public function test_the_same_claim_across_answers_engines_and_scans_updates_one_issue(): void
    {
        $hotel = $this->hotelWithFacts();
        $first = $this->scan($hotel, 7);
        $second = $this->scan($hotel);
        $a = $this->answer($first);
        $b = $this->answer($first, 'gemini');
        $c = $this->answer($second);

        foreach ([$a, $b, $c] as $response) {
            $this->apply($response, $this->claim('Kids club', 'Has a Kids Club', AccuracyVerdict::Wrong));
        }

        $issue = $hotel->accuracyIssues()->sole();
        $this->assertSame(3, $issue->occurrences);
        $this->assertSame(['openai', 'gemini'], $issue->engines);
        $this->assertSame([$c->id, $b->id, $a->id], $issue->example_response_ids);
        $this->assertSame([$first->id, $second->id], [$issue->first_seen_scan_id, $issue->last_seen_scan_id]);
    }

    public function test_a_different_wrong_value_for_the_same_fact_is_a_separate_issue(): void
    {
        $hotel = $this->hotelWithFacts();
        $response = $this->answer($this->scan($hotel));

        $this->apply($response,
            $this->claim('Kids club', 'has a kids club', AccuracyVerdict::Wrong),
            $this->claim('Kids club', 'kids club open until 10pm', AccuracyVerdict::Wrong),
        );

        $this->assertSame(2, $hotel->accuracyIssues()->count());
    }

    public function test_an_open_issue_not_seen_in_the_next_two_completed_scans_looks_fixed(): void
    {
        $hotel = $this->hotelWithFacts();
        $seen = $this->scan($hotel, 14);
        $this->apply($this->answer($seen), $this->claim('Kids club', 'has a kids club', AccuracyVerdict::Wrong));
        $service = app(AccuracyService::class);

        $next = $this->scan($hotel, 7);
        $service->resolveStale($hotel, $next);
        $this->assertSame(IssueStatus::Open, $hotel->accuracyIssues()->sole()->status, 'one scan without it is not enough');

        $after = $this->scan($hotel);
        $service->resolveStale($hotel, $after);
        $issue = $hotel->accuracyIssues()->sole();
        $this->assertSame(IssueStatus::Resolved, $issue->status);
        $this->assertTrue($issue->auto_resolved);
    }

    public function test_a_resolved_issue_seen_again_reopens_but_a_dismissed_one_stays_dismissed(): void
    {
        $hotel = $this->hotelWithFacts();
        $resolved = AccuracyIssue::factory()->for($hotel)->create([
            'claim_key' => 'issue|kids-club|has-a-kids-club', 'status' => IssueStatus::Resolved, 'auto_resolved' => true, 'resolved_at' => now(),
        ]);
        $dismissed = AccuracyIssue::factory()->for($hotel)->dismissed()->create(['claim_key' => 'issue|swimming-pool|no-pool', 'fact_key' => 'Swimming pool']);

        $this->apply($this->answer($this->scan($hotel)),
            $this->claim('Kids club', 'has a kids club', AccuracyVerdict::Wrong),
            $this->claim('Swimming pool', 'no pool', AccuracyVerdict::Wrong),
        );

        $this->assertSame([IssueStatus::Open, false], [$resolved->fresh()->status, $resolved->fresh()->auto_resolved]);
        $this->assertSame([IssueStatus::Dismissed, 2], [$dismissed->fresh()->status, $dismissed->fresh()->occurrences]);
    }

    public function test_a_missing_fact_is_resolved_once_the_hotel_enters_that_fact(): void
    {
        $hotel = $this->hotelWithFacts();
        $scan = $this->scan($hotel);
        $this->apply($this->answer($scan), $this->claim('Spa', 'has a spa', AccuracyVerdict::NotInFacts));

        $hotel->facts()->where('key', 'Spa')->update(['value' => 'Yes, open daily']);
        app(AccuracyService::class)->resolveStale($hotel, $scan);

        $this->assertSame(IssueStatus::Resolved, $hotel->accuracyIssues()->sole()->status);
    }

    public function test_price_location_and_facility_claims_are_high_severity_and_others_medium(): void
    {
        $this->assertSame(IssueSeverity::High, AccuracyService::severityFor('Price range per night', 'Rooms from USD 80'));
        $this->assertSame(IssueSeverity::High, AccuracyService::severityFor('Distance to airport', 'About 30 minutes away'));
        $this->assertSame(IssueSeverity::High, AccuracyService::severityFor('Parking', 'Free parking'));
        $this->assertSame(IssueSeverity::Medium, AccuracyService::severityFor('Check-in time', 'Check-in from 2pm'));
    }
}
