<?php

namespace Tests\Feature;

use App\Enums\Sentiment;
use App\Services\Analysis\AnswerExtractor;
use App\Services\Analysis\FakeAnalyzer;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Analysis\OpenAiAnswerExtractor;
use App\Services\Analysis\ResponseAnalyzer;
use App\Services\Engines\AskContext;
use App\Services\Engines\EngineContract;
use App\Services\Engines\FakeEngine;
use App\Services\Engines\OpenAiEngine;
use Tests\TestCase;

class FakeEngineTest extends TestCase
{
    private function context(int $run = 1): AskContext
    {
        return new AskContext(
            hotelName: 'Alaya Resort Ubud',
            city: 'Ubud',
            country: 'Indonesia',
            hotelWebsite: 'alayahotels.com',
            competitors: ['Kayon Jungle Resort' => 'https://kayonresort.com', 'Maya Ubud Resort' => null],
            runNumber: $run,
        );
    }

    public function test_answers_are_repeatable_per_prompt_and_run(): void
    {
        $engine = new FakeEngine;
        $prompt = 'What is the best family-friendly resort in Ubud?';

        $first = $engine->ask($prompt, $this->context(1));

        $this->assertEquals($first, (new FakeEngine)->ask($prompt, $this->context(1)));
        $this->assertNotEquals($first->text, $engine->ask($prompt, $this->context(2))->text);
        $this->assertNotEquals($first->text, (new FakeEngine(salt: 'week-2'))->ask($prompt, $this->context(1))->text);
    }

    public function test_answers_look_like_real_recommendations(): void
    {
        $engine = new FakeEngine;

        foreach (range(1, 30) as $run) {
            $result = $engine->ask('Recommend a hotel in Ubud for a honeymoon', $this->context($run));
            preg_match_all('/\*\*(.+?)\*\*/', $result->text, $names);

            $this->assertGreaterThanOrEqual(1, count($names[1]));
            $this->assertLessThanOrEqual(6, count($names[1]));
            $this->assertStringContainsString('Ubud', $result->text);
            $this->assertGreaterThanOrEqual(2, count($result->citations));
            $this->assertLessThanOrEqual(5, count($result->citations));
            $this->assertGreaterThan(1000, $result->tokensIn);
            $this->assertGreaterThan(0, $result->tokensOut);
            $this->assertContains($result->webSearchCalls, [1, 2]);

            foreach ($result->citations as $citation) {
                $this->assertStringNotContainsString('utm_source', $citation->url);
            }
        }
    }

    public function test_mention_rate_controls_how_often_the_hotel_appears(): void
    {
        $count = fn (FakeEngine $engine) => collect(range(1, 40))
            ->filter(fn ($run) => str_contains($engine->ask('best hotel in Ubud', $this->context($run))->text, '**Alaya Resort Ubud**'))
            ->count();

        $this->assertSame(40, $count(new FakeEngine(mentionRate: 1.0)));
        $this->assertSame(0, $count(new FakeEngine(mentionRate: 0.0)));
        $this->assertGreaterThan(5, $count(new FakeEngine));
        $this->assertLessThan(35, $count(new FakeEngine));
    }

    public function test_fake_analyzer_reads_the_fake_answers_back(): void
    {
        $analyzer = new ResponseAnalyzer(new FakeAnalyzer(new HotelNameMatcher), new HotelNameMatcher);
        $engine = new FakeEngine(mentionRate: 1.0, competitorRate: 1.0);
        $sentiments = [];

        foreach (range(1, 20) as $run) {
            $answer = $engine->ask('What are the best hotels to stay at in Ubud?', $this->context($run))->text;
            $result = $analyzer->analyze($answer, 'Alaya Resort Ubud', 'Ubud', ['Kayon Jungle Resort', 'Maya Ubud Resort']);

            $this->assertTrue($result->hotelMentioned);
            $this->assertNotSame(Sentiment::NotMentioned, $result->sentiment);
            $inAnswer = array_values(array_filter(
                ['Kayon Jungle Resort', 'Maya Ubud Resort'],
                fn (string $name) => str_contains($answer, "**{$name}**"),
            ));
            $this->assertNotEmpty($inAnswer);
            $this->assertEqualsCanonicalizing($inAnswer, $result->competitorsMentioned);
            $this->assertNotContains('Alaya Resort Ubud', $result->otherHotelsMentioned);

            if ($result->position !== null) {
                $this->assertMatchesRegularExpression('/^'.$result->position.'\. \*\*Alaya Resort Ubud\*\*/m', $answer);
            }

            $sentiments[$result->sentiment->value] = true;
        }

        $this->assertGreaterThanOrEqual(2, count($sentiments), 'answers carry mixed sentiment');
    }

    public function test_engine_switch_selects_fake_or_real_services(): void
    {
        config(['citevanta.engine' => 'fake']);
        $this->assertInstanceOf(FakeEngine::class, app(EngineContract::class));
        $this->assertInstanceOf(FakeAnalyzer::class, app(AnswerExtractor::class));

        config(['citevanta.engine' => 'openai', 'openai.api_key' => 'test-key']);
        $this->assertInstanceOf(OpenAiEngine::class, app(EngineContract::class));
        $this->assertInstanceOf(OpenAiAnswerExtractor::class, app(AnswerExtractor::class));
    }
}
