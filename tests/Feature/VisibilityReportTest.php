<?php

namespace Tests\Feature;

use App\Enums\Persona;
use App\Enums\Sentiment;
use App\Models\Citation;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ResponseAnalysis;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Reporting\VisibilityReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibilityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_helper(): void
    {
        $this->assertSame(33.3, VisibilityReportBuilder::percentage(1, 3));
        $this->assertSame(100.0, VisibilityReportBuilder::percentage(4, 4));
        $this->assertSame(0.0, VisibilityReportBuilder::percentage(0, 0));
    }

    public function test_it_aggregates_visibility_position_sentiment_share_of_voice_and_domains(): void
    {
        $hotel = Hotel::factory()->create(['name' => 'Alaya Resort Ubud', 'city' => 'Ubud']);
        $scan = ScanRun::factory()->for($hotel)->create(['cost_usd' => 0.1234]);
        $family = Prompt::factory()->for($hotel)->create(['persona' => Persona::Family]);
        $couple = Prompt::factory()->for($hotel)->create(['persona' => Persona::Couple]);

        $answer = function (Prompt $prompt, ?array $analysis, array $domains = []) use ($scan) {
            $response = ScanResponse::factory()->for($scan)->for($prompt)->create([
                'tokens_in' => 100, 'tokens_out' => 50, 'web_search_calls' => 1,
            ]);
            if ($analysis !== null) {
                ResponseAnalysis::factory()->create(['response_id' => $response->id] + $analysis);
            }
            foreach ($domains as $domain) {
                Citation::factory()->create(['response_id' => $response->id, 'domain' => $domain]);
            }
        };

        // Family: 2 of 3 mention us (positions 1 and 3).
        $answer($family, ['hotel_mentioned' => true, 'position' => 1, 'sentiment' => Sentiment::Positive, 'competitors_mentioned' => ['Kayon']], ['tripadvisor.com', 'booking.com']);
        $answer($family, ['hotel_mentioned' => true, 'position' => 3, 'sentiment' => Sentiment::Neutral, 'competitors_mentioned' => ['Kayon', 'Maya Ubud']], ['tripadvisor.com']);
        $answer($family, ['competitors_mentioned' => ['Kayon']], ['tripadvisor.com']);
        // Couple: 1 of 2 mention us, not in a list (no position).
        $answer($couple, ['hotel_mentioned' => true, 'position' => null, 'sentiment' => Sentiment::Negative]);
        $answer($couple, ['competitors_mentioned' => ['Maya Ubud']], ['booking.com']);
        // Collected but not analysed: excluded from percentages.
        $answer($couple, null);

        $report = app(VisibilityReportBuilder::class)->build($scan, ['Kayon', 'Maya Ubud', 'Four Seasons']);

        $this->assertSame(5, $report->totalAnswers);
        $this->assertSame(3, $report->mentions);
        $this->assertSame(60.0, $report->visibility);

        $this->assertSame(
            ['answers' => 3, 'mentions' => 2, 'visibility' => 66.7, 'avg_position' => 2.0, 'positive' => 1, 'neutral' => 1, 'negative' => 0, 'not_mentioned' => 1],
            $report->perPersona['family'],
        );
        $this->assertSame(
            ['answers' => 2, 'mentions' => 1, 'visibility' => 50.0, 'avg_position' => null, 'positive' => 0, 'neutral' => 0, 'negative' => 1, 'not_mentioned' => 1],
            $report->perPersona['couple'],
        );
        $this->assertArrayNotHasKey('business', $report->perPersona);

        $this->assertSame(2.0, $report->averagePosition);

        $this->assertSame(['positive' => 1, 'neutral' => 1, 'negative' => 1, 'not_mentioned' => 2], $report->sentimentCounts);

        // Mentions: Kayon 3, us 3, Maya 2, Four Seasons 0 -> 8 tracked mentions.
        $sov = collect($report->shareOfVoice)->keyBy('name');
        $this->assertSame('Alaya Resort Ubud', $report->shareOfVoice[0]['name'], 'ties rank our hotel first');
        $this->assertSame(['mentions' => 3, 'visibility' => 60.0, 'share_of_voice' => 37.5], array_intersect_key($sov['Kayon'], array_flip(['mentions', 'visibility', 'share_of_voice'])));
        $this->assertSame(37.5, $sov['Alaya Resort Ubud']['share_of_voice']);
        $this->assertSame(25.0, $sov['Maya Ubud']['share_of_voice']);
        $this->assertSame(0, $sov['Four Seasons']['mentions']);

        $this->assertSame([
            ['domain' => 'tripadvisor.com', 'citations' => 3],
            ['domain' => 'booking.com', 'citations' => 2],
        ], $report->topDomains);

        $this->assertSame(600, $report->tokensIn);
        $this->assertSame(6, $report->webSearchCalls);
        $this->assertSame(0.1234, $report->costUsd);
    }

    public function test_empty_scan_reports_zero_without_dividing_by_zero(): void
    {
        $report = app(VisibilityReportBuilder::class)->build(ScanRun::factory()->create(), ['X']);

        $this->assertSame(0, $report->totalAnswers);
        $this->assertSame(0.0, $report->visibility);
        $this->assertNull($report->averagePosition);
        $this->assertSame(0.0, $report->shareOfVoice[0]['share_of_voice']);
    }
}
