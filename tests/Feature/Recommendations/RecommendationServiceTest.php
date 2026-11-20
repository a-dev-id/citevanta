<?php

namespace Tests\Feature\Recommendations;

use App\Enums\HotelHasFeature;
use App\Enums\IssueStatus;
use App\Enums\RecommendationStatus;
use App\Models\AccuracyIssue;
use App\Models\CompetitorInsight;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\User;
use App\Models\VisibilityScore;
use App\Services\Recommendations\RecommendationService;
use App\Services\Recommendations\RecommendationWriter;
use App\Services\Recommendations\RecommendationWriterResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class RecommendationServiceTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    private function hotel(): Hotel
    {
        $hotel = $this->scannableHotel(prompts: 0);
        $hotel->facts()->create(['key' => 'Kids club', 'value' => 'Yes, ages 4-12', 'position' => 1]);
        $hotel->facts()->create(['key' => 'Spa', 'value' => 'No', 'position' => 2]);

        return $hotel;
    }

    /** A completed scan with the hotel's website cited, and the given family visibility. */
    private function scan(Hotel $hotel, int $familyMentions = 1, int $daysAgo = 0): ScanRun
    {
        $scan = ScanRun::factory()->for($hotel)->create([
            'finished_at' => now()->subDays($daysAgo),
            'citation_summary' => ['total_citations' => 3, 'own_domain' => 'alayahotels.com', 'own_cited' => true, 'own_citations' => 2, 'domains' => [['domain' => 'alayahotels.com', 'citations' => 2, 'answers' => 2]]],
        ]);
        VisibilityScore::factory()->for($scan)->create(['hotel_id' => $hotel->id, 'engine' => 'openai', 'persona' => 'family', 'mentions' => $familyMentions, 'answers' => 10, 'visibility_pct' => $familyMentions * 10]);

        return $scan;
    }

    private function refresh(ScanRun $scan): array
    {
        return app(RecommendationService::class)->refresh($scan->fresh());
    }

    public function test_a_scan_creates_recommendations_with_evidence_and_a_second_scan_updates_them_without_duplicates(): void
    {
        $hotel = $this->hotel();
        $first = $this->scan($hotel, 1, 7);
        $this->assertSame(1, $this->refresh($first)['created']);

        $recommendation = $hotel->recommendations()->sole();
        $this->assertSame('persona-visibility:family', $recommendation->key);
        $this->assertSame(RecommendationStatus::New, $recommendation->status);
        $this->assertSame(['type' => 'persona', 'value' => 'family'], $recommendation->metric);
        $this->assertStringContainsString('1 of 10 answers', $recommendation->why);

        $second = $this->scan($hotel, 2);
        $this->assertSame(['created' => 0, 'updated' => 1, 'looks_done' => 0], $this->refresh($second));

        $recommendation->refresh();
        $this->assertSame(1, $hotel->recommendations()->count());
        $this->assertSame($second->id, $recommendation->last_seen_scan_id);
        $this->assertStringContainsString('2 of 10 answers', $recommendation->why, 'fresh evidence');
    }

    public function test_evidence_gone_marks_looks_done_and_evidence_back_clears_it(): void
    {
        $hotel = $this->hotel();
        $this->refresh($this->scan($hotel, 1, 14));

        $this->assertSame(1, $this->refresh($this->scan($hotel, 8, 7))['looks_done'], 'family visibility is 80% now');
        $this->assertNotNull($hotel->recommendations()->sole()->looks_done_at);
        $this->assertSame(RecommendationStatus::New, $hotel->recommendations()->sole()->status, 'the team confirms; it is not closed for them');

        $this->refresh($this->scan($hotel, 1));
        $this->assertNull($hotel->recommendations()->sole()->looks_done_at);
    }

    public function test_done_and_dismissed_keep_their_status_when_the_evidence_is_still_there(): void
    {
        $hotel = $this->hotel();
        $this->refresh($this->scan($hotel, 1, 7));
        $recommendation = $hotel->recommendations()->sole();
        app(RecommendationService::class)->setStatus($recommendation, RecommendationStatus::Dismissed, User::factory()->create(), 'We are adults only');

        $this->refresh($this->scan($hotel, 1));

        $this->assertSame([RecommendationStatus::Dismissed, 'We are adults only'], [$recommendation->fresh()->status, $recommendation->fresh()->dismissed_reason]);
    }

    public function test_ai_text_is_kept_while_the_evidence_is_unchanged_and_replaced_when_it_changes(): void
    {
        $hotel = $this->hotel();
        $this->refresh($this->scan($hotel, 1, 14));
        $recommendation = $hotel->recommendations()->sole();
        $recommendation->update(['title' => 'AI wording', 'ai_written' => true]);

        $this->refresh($this->scan($hotel, 1, 7));
        $this->assertSame(['AI wording', true], [$recommendation->fresh()->title, $recommendation->fresh()->ai_written]);

        $this->refresh($this->scan($hotel, 3));
        $this->assertSame(false, $recommendation->fresh()->ai_written);
        $this->assertStringContainsString('3 of 10 answers', $recommendation->fresh()->why);
    }

    public function test_only_the_latest_completed_scan_changes_recommendations(): void
    {
        $hotel = $this->hotel();
        $old = $this->scan($hotel, 1, 7);
        $this->scan($hotel, 9);

        $this->assertSame(['created' => 0, 'updated' => 0, 'looks_done' => 0], $this->refresh($old));
        $this->assertSame(0, $hotel->recommendations()->count());
    }

    public function test_accuracy_issues_and_missing_facts_become_recommendations(): void
    {
        $hotel = $this->hotel();
        $scan = $this->scan($hotel, 9);
        $issue = AccuracyIssue::factory()->for($hotel)->create(['fact_key' => 'Spa', 'ai_claim' => 'Alaya has a large spa.', 'true_value' => 'No']);
        AccuracyIssue::factory()->for($hotel)->missingFact()->create(['fact_key' => 'Airport transfer']);

        $this->refresh($scan);

        $fix = $hotel->recommendations()->where('key', 'accuracy:'.$issue->id)->sole();
        $this->assertSame('Correct what AI says about Spa', $fix->title);
        $this->assertStringContainsString('#issue-'.$issue->id, $fix->evidence[0]['url']);
        $this->assertStringContainsString('Airport transfer', $hotel->recommendations()->where('key', 'missing-facts')->sole()->why);

        $issue->update(['status' => IssueStatus::Resolved]);
        $this->refresh($scan);
        $this->assertNotNull($fix->fresh()->looks_done_at, 'issue fixed: recommendation looks done');
    }

    public function test_competitor_features_the_hotel_has_or_lacks_become_recommendations(): void
    {
        $hotel = $this->hotel();
        $scan = $this->scan($hotel, 9);
        $prompt = Prompt::factory()->for($hotel)->create();
        CompetitorInsight::create([
            'hotel_id' => $hotel->id, 'scan_run_id' => $scan->id, 'prompt_id' => $prompt->id, 'competitors' => ['Kayon Jungle Resort'], 'wins' => 2, 'sources' => [],
            'reasons' => [
                ['competitor' => 'Kayon Jungle Resort', 'reason' => 'kids club', 'quote' => '', 'fact_key' => 'Kids club', 'hotel_has' => HotelHasFeature::HasIt->value],
                ['competitor' => 'Kayon Jungle Resort', 'reason' => 'spa', 'quote' => '', 'fact_key' => 'Spa', 'hotel_has' => HotelHasFeature::LacksIt->value],
            ],
        ]);

        $this->refresh($scan);

        $tell = $hotel->recommendations()->where('key', 'feature:kids-club')->sole();
        $this->assertSame('Tell AI about your kids club', $tell->title);
        $this->assertStringContainsString('Yes, ages 4-12', $tell->why);
        $this->assertSame(['type' => 'prompt', 'value' => $prompt->id], $tell->metric);
        $this->assertStringContainsString('spa', $hotel->recommendations()->where('key', 'different-strengths')->sole()->why);
    }

    public function test_a_website_that_is_not_cited_gets_website_and_schema_recommendations(): void
    {
        $hotel = $this->hotel();
        $scan = $this->scan($hotel, 9);
        $scan->update(['citation_summary' => ['total_citations' => 4, 'own_domain' => 'alayahotels.com', 'own_cited' => false, 'own_citations' => 0, 'domains' => [['domain' => 'booking.com', 'citations' => 4, 'answers' => 3]]]]);

        $this->refresh($scan);

        $this->assertEqualsCanonicalizing(['website-not-cited', 'schema-markup'], $hotel->recommendations()->pluck('key')->all());
    }

    public function test_measured_impact_waits_for_the_next_scan_then_shows_the_change(): void
    {
        $hotel = $this->hotel();
        $before = $this->scan($hotel, 1, 7);
        $this->refresh($before);
        $recommendation = $hotel->recommendations()->sole();
        $service = app(RecommendationService::class);

        $service->setStatus($recommendation, RecommendationStatus::Done, User::factory()->create());
        $recommendation->refresh();
        $this->assertSame($before->id, $recommendation->done_baseline_scan_id);
        $this->assertSame('Not enough data yet: impact shows after the next completed scan.', $service->measuredImpact($recommendation)['note']);

        $this->travel(1)->days();
        $this->scan($hotel, 4);

        $impact = $service->measuredImpact($recommendation->fresh());
        $this->assertSame([10.0, 40.0, 30.0, null], [$impact['before'], $impact['after'], $impact['change'], $impact['note']]);
    }

    public function test_the_writer_runs_one_ai_call_per_scan_and_flags_numbers_that_are_not_in_the_facts(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $this->app->bind(RecommendationWriter::class, fn () => new class implements RecommendationWriter
        {
            public function write(string $hotelName, string $city, array $facts, array $items): RecommendationWriterResult
            {
                $out = [];
                foreach ($items as $item) {
                    $out[$item['key']] = ['title' => $item['title'], 'why' => $item['why'].' The hotel is 15 minutes from the airport.', 'steps' => $item['steps'], 'where' => $item['where']];
                }

                return new RecommendationWriterResult($out, 1000, 400, 'gpt-5-mini');
            }
        });

        [$scan] = $this->completedScans($hotel, [0.1]);

        $this->assertSame(1, $scan->aiCalls()->where('purpose', 'recommendations')->count());
        $this->assertGreaterThan(0, (float) $scan->cost_breakdown['recommendations']);
        $written = $hotel->recommendations()->where('ai_written', true)->get();
        $this->assertNotEmpty($written);
        $this->assertTrue($written->every(fn (Recommendation $r) => str_contains($r->why, '[check: 15 minutes]')));
    }

    public function test_recommendations_can_be_switched_off(): void
    {
        config(['citevanta.recommendations.enabled' => false]);
        $hotel = $this->scannableHotel(prompts: 5);
        $this->completedScans($hotel, [0.1]);

        $this->assertSame(0, $hotel->recommendations()->count());
    }
}
