<?php

namespace Tests\Feature;

use App\Enums\ScanStatus;
use App\Models\Citation;
use App\Models\Competitor;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ResponseAnalysis;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Analysis\AnswerExtractor;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Fakes\FakeAnswerExtractor;
use Tests\Fakes\StubEngine;
use Tests\TestCase;

class ScanTestCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = "Top picks:\n1. Maya Ubud Resort - great pool\n2. ALAYA Ubud - central and family friendly\n3. Kayon Jungle Resort - quiet";

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'citevanta.analysis_model' => 'gpt-5-mini',
            'citevanta.prices.models' => [
                'fake-model' => ['input_per_million' => 1.0, 'output_per_million' => 2.0],
                'gpt-5-mini' => ['input_per_million' => 0.0, 'output_per_million' => 0.0],
            ],
            'citevanta.prices.web_search_per_1000_calls' => 10.0,
        ]);
        $this->app->instance(AnswerExtractor::class, new FakeAnswerExtractor);
    }

    private function useEngine(StubEngine $engine): StubEngine
    {
        $this->app->instance(EngineContract::class, $engine);
        // Scan jobs resolve engines by key through the EngineManager.
        $this->app->make(EngineManager::class)->swap('openai', $engine);

        return $engine;
    }

    public function test_it_scans_saves_everything_and_prints_the_report(): void
    {
        $engine = $this->useEngine(StubEngine::answering(self::ANSWER, [
            'https://www.tripadvisor.com/a?utm_source=openai',
            'https://booking.com/b',
        ]));

        $this->artisan('scan:test', [
            'hotel' => 'Alaya Resort Ubud',
            'city' => 'Ubud',
            '--country' => 'Indonesia',
            '--competitors' => 'Kayon Jungle Resort, Four Seasons Sayan',
            '--runs' => 2,
        ])
            ->expectsOutputToContain('10 prompts × 2 runs = 20 answers')
            ->expectsOutputToContain('Overall visibility: 100% (20 of 20 analysed answers mention the hotel)')
            ->expectsOutputToContain('Average position when mentioned: #2')
            ->expectsOutputToContain('tripadvisor.com')
            ->expectsOutputToContain('Total cost: $0.2400')
            ->assertSuccessful();

        // Prompts never contain the hotel's name.
        $this->assertCount(20, $engine->prompts);
        foreach ($engine->prompts as $prompt) {
            $this->assertStringNotContainsStringIgnoringCase('Alaya', $prompt);
        }

        $hotel = Hotel::sole();
        $this->assertSame('Indonesia', $hotel->country);
        $this->assertSame(['Four Seasons Sayan', 'Kayon Jungle Resort'], $hotel->competitors()->orderBy('name')->pluck('name')->all());
        $this->assertSame(10, Prompt::count());

        $scan = ScanRun::sole();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame('openai', $scan->engine, 'the engine slot the stub stands in for');
        $this->assertSame(['fake-model'], $scan->responses()->distinct()->pluck('model')->all());
        $this->assertNotNull($scan->finished_at);
        // Engine tokens: 20 × (1000 in × $1/M + 500 out × $2/M) = $0.04
        // Web search:   20 calls × $10/1000                     = $0.20
        // Analysis model is priced at $0 in this test.
        $this->assertEqualsWithDelta(0.24, (float) $scan->cost_usd, 1e-6);

        $this->assertSame(20, ScanResponse::count());
        $this->assertSame(40, Citation::count());
        $this->assertSame('https://www.tripadvisor.com/a', Citation::first()->url);

        $analysis = ResponseAnalysis::first();
        $this->assertTrue($analysis->hotel_mentioned);
        $this->assertSame(2, $analysis->position);
        $this->assertSame(['Kayon Jungle Resort'], $analysis->competitors_mentioned);
        $this->assertSame(['Maya Ubud Resort'], $analysis->other_hotels_mentioned);
    }

    public function test_rerunning_updates_the_hotel_and_reuses_prompts(): void
    {
        $this->useEngine(StubEngine::answering('No hotels here.'));
        $args = ['hotel' => 'Alaya Resort Ubud', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1];

        $this->artisan('scan:test', $args + ['--competitors' => 'Kayon'])->assertSuccessful();
        $this->artisan('scan:test', ['--country' => 'Republic of Indonesia', '--type' => 'resort'] + $args)
            ->expectsOutputToContain('competitors: Kayon')
            ->expectsOutputToContain('Overall visibility: 0%')
            ->expectsOutputToContain('n/a (never ranked in a list)')
            ->assertSuccessful();

        $this->assertSame(1, Hotel::count());
        $this->assertSame('Republic of Indonesia', Hotel::sole()->country);
        $this->assertSame(1, Competitor::count());
        $this->assertSame(2, ScanRun::count());
        $this->assertSame(20, Prompt::count(), 'second run used "resort" wording, so 10 new prompts');
        $this->assertSame(10, Prompt::where('is_active', true)->count(), 'the previous set is deactivated');
        $this->assertTrue(Prompt::where('is_active', true)->get()->every(fn ($p) => str_contains($p->text, 'resort')));
    }

    public function test_competitors_are_only_added_by_default(): void
    {
        $this->useEngine(StubEngine::answering('No hotels here.'));
        $args = ['hotel' => 'Alaya Resort Ubud', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1];

        $this->artisan('scan:test', $args + ['--competitors' => 'Kayon,Maya Ubud'])->assertSuccessful();
        $this->artisan('scan:test', $args + ['--competitors' => 'Komaneka'])
            ->expectsOutputToContain('competitors: Kayon, Maya Ubud, Komaneka')
            ->assertSuccessful();

        $this->assertSame(['Kayon', 'Maya Ubud', 'Komaneka'], Competitor::orderBy('id')->pluck('name')->all());
        $this->assertSame(0, Competitor::onlyTrashed()->count(), 'nothing was removed');
    }

    public function test_replace_competitors_removes_unlisted_ones_with_force(): void
    {
        $this->useEngine(StubEngine::answering('No hotels here.'));
        $args = ['hotel' => 'Alaya Resort Ubud', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1];
        $this->artisan('scan:test', $args + ['--competitors' => 'Kayon,Maya Ubud'])->assertSuccessful();

        $this->artisan('scan:test', $args + ['--competitors' => 'Komaneka', '--replace-competitors' => true, '--force' => true])
            ->expectsOutputToContain('competitors: Komaneka')
            ->assertSuccessful();

        $this->assertSame(['Komaneka'], Competitor::pluck('name')->all());
        $this->assertSame(['Kayon', 'Maya Ubud'], Competitor::onlyTrashed()->orderBy('id')->pluck('name')->all(), 'soft deleted, history kept');
    }

    public function test_replace_competitors_asks_first_and_can_be_cancelled(): void
    {
        $this->useEngine(StubEngine::answering('No hotels here.'));
        $args = ['hotel' => 'Alaya Resort Ubud', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1];
        $this->artisan('scan:test', $args + ['--competitors' => 'Kayon'])->assertSuccessful();

        $this->artisan('scan:test', $args + ['--competitors' => 'Komaneka', '--replace-competitors' => true])
            ->expectsConfirmation('This removes every competitor of "Alaya Resort Ubud" that is not in --competitors. Continue?', 'no')
            ->expectsOutputToContain('Cancelled. No competitors were changed.')
            ->assertFailed();

        $this->assertSame(['Kayon'], Competitor::pluck('name')->all());
        $this->assertSame(1, ScanRun::count(), 'no scan was started');
    }

    public function test_transient_errors_are_retried_and_permanent_failures_are_skipped(): void
    {
        $attemptsPerPrompt = [];
        $this->useEngine(new StubEngine(function (string $prompt) use (&$attemptsPerPrompt) {
            $attemptsPerPrompt[$prompt] = ($attemptsPerPrompt[$prompt] ?? 0) + 1;

            if (str_contains($prompt, 'honeymoon')) {
                throw new RuntimeException('upstream down');            // always fails
            }
            if ($attemptsPerPrompt[$prompt] < 3) {
                throw new RuntimeException('temporary glitch');         // fails twice, then works
            }

            return self::ANSWER;
        }));

        $this->artisan('scan:test', [
            'hotel' => 'Alaya Resort Ubud', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1,
        ])
            ->expectsOutputToContain('1 call(s) failed after retries and were skipped')
            ->expectsOutputToContain('upstream down')
            ->expectsOutputToContain('Overall visibility: 100% (9 of 9')
            ->assertSuccessful();

        $this->assertSame(9, ScanResponse::count());
        $this->assertSame(ScanStatus::CompletedWithErrors, ScanRun::sole()->status);
        $this->assertSame(3, max($attemptsPerPrompt), 'never more than 3 attempts');
        Sleep::assertSleptTimes(2 * 10);
    }

    public function test_scan_fails_when_every_call_fails(): void
    {
        $this->useEngine(new StubEngine(fn () => throw new RuntimeException('down')));

        $this->artisan('scan:test', ['hotel' => 'H', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1])
            ->assertFailed();

        $this->assertSame(ScanStatus::Failed, ScanRun::sole()->status);
    }

    public function test_country_is_required(): void
    {
        $this->useEngine(StubEngine::answering('x'));

        $this->artisan('scan:test', ['hotel' => 'H', 'city' => 'Ubud'])
            ->expectsOutputToContain('--country option is required')
            ->assertExitCode(2);

        $this->assertSame(0, Hotel::count());
    }

    public function test_missing_api_key_stops_before_calling_the_real_engine(): void
    {
        $this->app->forgetInstance(EngineContract::class);
        $this->app->forgetInstance(AnswerExtractor::class);
        config(['openai.api_key' => null, 'citevanta.engine' => 'openai']);

        $this->artisan('scan:test', ['hotel' => 'H', 'city' => 'Ubud', '--country' => 'Indonesia'])
            ->expectsOutputToContain('OPENAI_API_KEY is not set')
            ->assertFailed();

        $this->assertSame(0, ScanRun::count());
    }
}
