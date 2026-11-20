<?php

namespace Tests\Feature\Scans;

use App\Enums\Persona;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\Sentiment;
use App\Jobs\FinalizeScanRunJob;
use App\Jobs\ScanHotelJob;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\VisibilityScore;
use App\Services\Scanning\ScanFinalizer;
use App\Services\Scanning\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class FinalizeScanRunTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /**
     * A scan with hand-made answers so every number is known.
     */
    private function scanWithAnswers(): ScanRun
    {
        config(['citevanta.analysis_model' => 'gpt-5-mini', 'citevanta.prices.models.answer-model' => ['input_per_million' => 1.0, 'output_per_million' => 2.0]]);
        $hotel = $this->scannableHotel(prompts: 0);
        $family = $hotel->prompts()->create(['text' => 'Best family resort in Ubud with a pool?', 'persona' => Persona::Family, 'language' => 'en', 'is_active' => true]);
        $couple = $hotel->prompts()->create(['text' => 'Most romantic resort in Ubud for a honeymoon?', 'persona' => Persona::Couple, 'language' => 'en', 'is_active' => true]);

        $run = $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'answer-model', 'trigger' => ScanTrigger::Manual,
            'status' => ScanStatus::Running, 'started_at' => now()->subMinutes(5),
            'prompt_ids' => [$family->id, $couple->id], 'runs_per_prompt' => 2, // 4 planned calls
        ]);

        $answer = function ($prompt, int $runNumber, array $analysis, array $domains = []) use ($run) {
            $response = $run->responses()->create([
                'prompt_id' => $prompt->id, 'engine' => 'openai', 'model' => 'answer-model', 'run_number' => $runNumber,
                'answer_text' => 'answer', 'tokens_in' => 1000, 'tokens_out' => 500, 'web_search_calls' => 1,
            ]);
            $response->analysis()->create($analysis + [
                'competitors_mentioned' => [], 'other_hotels_mentioned' => [], 'summary' => 's', 'tokens_in' => 0, 'tokens_out' => 0,
            ]);
            foreach ($domains as $domain) {
                $response->citations()->create(['url' => "https://{$domain}/x", 'domain' => $domain]);
            }
        };

        $answer($family, 1, ['hotel_mentioned' => true, 'position' => 1, 'sentiment' => Sentiment::Positive, 'competitors_mentioned' => ['Kayon Jungle Resort']], ['tripadvisor.com', 'alayahotels.com']);
        $answer($family, 2, ['hotel_mentioned' => true, 'position' => 3, 'sentiment' => Sentiment::Negative], ['tripadvisor.com']);
        $answer($couple, 1, ['hotel_mentioned' => false, 'position' => null, 'sentiment' => Sentiment::NotMentioned, 'competitors_mentioned' => ['Kayon Jungle Resort', 'Maya Ubud Resort']], ['booking.com']);
        // Couple run 2 never answered: one failed call.

        return $run;
    }

    public function test_it_stores_visibility_competitor_and_citation_results(): void
    {
        $run = $this->scanWithAnswers();

        (new FinalizeScanRunJob($run->id, runInsights: false))->handle(app(ScanFinalizer::class));
        $run->refresh();

        $overall = $run->visibilityScores()->whereNull('persona')->sole();
        $this->assertSame(['openai', 2, 3, 66.7, 2.0, 1, 0, 1], [
            $overall->engine, $overall->mentions, $overall->answers, $overall->visibility_pct,
            $overall->avg_position, $overall->positive, $overall->neutral, $overall->negative,
        ]);

        $family = $run->visibilityScores()->where('persona', 'family')->sole();
        $this->assertSame([2, 2, 100.0], [$family->mentions, $family->answers, $family->visibility_pct]);
        $couple = $run->visibilityScores()->where('persona', 'couple')->sole();
        $this->assertSame([0, 1, 0.0, null], [$couple->mentions, $couple->answers, $couple->visibility_pct, $couple->avg_position]);

        // Share of voice: hotel 2, Kayon 2, Maya 1 = 5 tracked mentions.
        $scores = $run->competitorScores()->with('competitor')->get()->keyBy(fn ($s) => $s->competitor?->name ?? 'self');
        $this->assertSame([2, 66.7, 40.0], [$scores['self']->mentions, $scores['self']->visibility_pct, $scores['self']->share_of_voice_pct]);
        $this->assertSame([2, 40.0], [$scores['Kayon Jungle Resort']->mentions, $scores['Kayon Jungle Resort']->share_of_voice_pct]);
        $this->assertSame([1, 20.0], [$scores['Maya Ubud Resort']->mentions, $scores['Maya Ubud Resort']->share_of_voice_pct]);
        $this->assertNull($scores['self']->competitor_id);

        $summary = $run->citation_summary;
        $this->assertSame(4, $summary['total_citations']);
        $this->assertSame('alayahotels.com', $summary['own_domain']);
        $this->assertTrue($summary['own_cited']);
        $this->assertSame(['domain' => 'tripadvisor.com', 'citations' => 2, 'answers' => 2], $summary['domains'][0]);

        $this->assertSame(ScanStatus::CompletedWithErrors, $run->status, '1 of 4 planned calls has no answer');
        $this->assertSame(3, $run->answers_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertNotNull($run->finished_at);
        // 3 answers x (1000 x $1/M + 500 x $2/M) + 3 web searches x $0.01
        $this->assertEqualsWithDelta(3 * 0.002 + 0.03, (float) $run->cost_usd, 1e-6);
        $this->assertEqualsWithDelta(3 * 0.002 + 0.03, $run->cost_breakdown['scan'], 1e-6);
        $this->assertSame(['scan', 'analysis', 'accuracy', 'competitor_reasons', 'recommendations'], array_keys($run->cost_breakdown));
    }

    public function test_status_is_completed_when_every_call_was_answered_and_failed_without_answers(): void
    {
        $run = $this->scanWithAnswers();
        $run->update(['runs_per_prompt' => 1, 'prompt_ids' => [$run->prompt_ids[0]]]);
        $run->responses()->where('run_number', 2)->delete();

        $this->assertSame(ScanStatus::Completed, app(ScanFinalizer::class)->finalize($run)->status);

        $run->responses()->delete();
        $this->assertSame(ScanStatus::Failed, app(ScanFinalizer::class)->finalize($run->fresh())->status);
    }

    public function test_finalizing_twice_replaces_the_scores(): void
    {
        $run = $this->scanWithAnswers();
        $finalizer = app(ScanFinalizer::class);

        $finalizer->finalize($run);
        $finalizer->finalize($run->fresh());

        $this->assertSame(3, $run->visibilityScores()->count(), 'overall + family + couple');
        $this->assertSame(3, $run->competitorScores()->count());
    }

    public function test_scan_test_and_the_background_path_give_identical_numbers(): void
    {
        $hotel = $this->scannableHotel(prompts: 5);

        // Background: ScanHotelJob -> batch of ScanPromptJobs -> FinalizeScanRunJob (sync queue in tests).
        $background = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'pending', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Queued]);
        ScanHotelJob::dispatch($background);

        // scan:test path: the same jobs, run by ScanService::runSync.
        $sync = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'pending', 'trigger' => ScanTrigger::Cli, 'status' => ScanStatus::Queued]);
        app(ScanService::class)->runSync($sync);

        $snapshot = fn (ScanRun $run) => [
            'status' => $run->fresh()->status,
            'answers' => $run->fresh()->answers_count,
            // Recommendation wording is excluded: the second scan only rewrites what is new.
            'cost' => collect($run->fresh()->cost_breakdown)->except('recommendations')->map(fn ($c) => round((float) $c, 6))->all(),
            'scores' => VisibilityScore::where('scan_run_id', $run->id)->orderBy('persona')
                ->get(['engine', 'persona', 'mentions', 'answers', 'visibility_pct', 'avg_position', 'positive', 'neutral', 'negative'])->toArray(),
            'sov' => $run->competitorScores()->orderBy('id')->get(['competitor_id', 'mentions', 'visibility_pct', 'share_of_voice_pct'])->toArray(),
            'citations' => $run->fresh()->citation_summary,
        ];

        $this->assertSame(ScanStatus::Completed, $background->fresh()->status);
        $this->assertSame(10, $background->fresh()->answers_count, '5 prompts x 2 runs x 1 engine');
        $this->assertEquals($snapshot($background), $snapshot($sync));
    }

    public function test_the_scheduled_scan_command_and_queue_mark_a_hotel_scan_end_to_end(): void
    {
        $hotel = $this->scannableHotel(prompts: 2);

        $this->artisan('citevanta:dispatch-due-scans')->expectsOutputToContain('1 scheduled scan(s) queued.');

        $run = Hotel::find($hotel->id)->scanRuns()->sole();
        $this->assertSame(ScanTrigger::Scheduled, $run->trigger);
        $this->assertSame(ScanStatus::Completed, $run->status, 'the sync test queue runs it straight away');
        $this->assertSame(4, $run->answers_count);
    }
}
