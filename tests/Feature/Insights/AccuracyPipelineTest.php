<?php

namespace Tests\Feature\Insights;

use App\Enums\AiPurpose;
use App\Jobs\AccuracyCheckJob;
use App\Models\ScanRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class AccuracyPipelineTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    private function mentioningAnswers(ScanRun $scan): int
    {
        return $scan->responses()->whereHas('analysis', fn ($q) => $q->where('hotel_mentioned', true))->count();
    }

    public function test_a_scan_checks_every_answer_that_mentions_the_hotel_once_and_records_the_cost(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $hotel->facts()->create(['key' => 'Swimming pool', 'value' => 'No pool, only a plunge pool', 'position' => 1]);
        [$scan] = $this->completedScans($hotel, [0.9]);

        $calls = $scan->aiCalls()->where('purpose', AiPurpose::Accuracy->value)->get();
        $this->assertGreaterThan(0, $calls->count());
        $this->assertSame($this->mentioningAnswers($scan), $calls->count());
        $this->assertSame(
            $scan->responses()->whereHas('analysis', fn ($q) => $q->where('hotel_mentioned', true))->pluck('id')->map(fn ($id) => "response:{$id}")->sort()->values()->all(),
            $calls->pluck('subject')->sort()->values()->all(),
        );

        $this->assertEqualsWithDelta((float) $calls->sum('cost_usd'), $scan->cost_breakdown['accuracy'], 1e-6);
        $this->assertEqualsWithDelta(array_sum($scan->cost_breakdown), (float) $scan->cost_usd, 1e-6);
    }

    public function test_a_retried_accuracy_job_does_not_call_or_count_twice(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        $hotel->facts()->create(['key' => 'Swimming pool', 'value' => 'No pool', 'position' => 1]);
        [$scan] = $this->completedScans($hotel, [0.9]);
        $responseId = $scan->aiCalls()->where('purpose', 'accuracy')->firstOrFail()->subject;
        $before = [$scan->aiCalls()->count(), (int) $hotel->accuracyIssues()->sum('occurrences')];

        Bus::dispatchNow(new AccuracyCheckJob($scan->id, (int) str_replace('response:', '', $responseId)));

        $this->assertSame($before, [$scan->aiCalls()->count(), (int) $hotel->accuracyIssues()->sum('occurrences')]);
    }

    public function test_accuracy_checks_can_be_switched_off(): void
    {
        config(['citevanta.insights.accuracy.enabled' => false]);
        $hotel = $this->scannableHotel(prompts: 5);
        [$scan] = $this->completedScans($hotel, [0.9]);

        $this->assertGreaterThan(0, $this->mentioningAnswers($scan));
        $this->assertSame(0, $scan->aiCalls()->where('purpose', AiPurpose::Accuracy->value)->count());
        $this->assertSame(0.0, (float) $scan->cost_breakdown['accuracy']);
    }

    public function test_the_fake_checker_finds_a_facility_the_facts_say_the_hotel_lacks(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);
        foreach (['Swimming pool', 'Spa', 'Restaurant', 'Free Wi-Fi', 'Kids club', 'Distance to airport'] as $i => $key) {
            $hotel->facts()->create(['key' => $key, 'value' => 'No', 'position' => $i]);
        }

        $this->completedScans($hotel, [1.0]);

        $issue = $hotel->accuracyIssues()->issues()->firstOrFail();
        $this->assertSame('No', $issue->true_value);
        $this->assertNotEmpty($issue->quote);
    }
}
