<?php

namespace Tests\Unit;

use App\Enums\Sentiment;
use App\Services\Analysis\ExtractedAnswer;
use App\Services\Analysis\HotelNameMatcher;
use App\Services\Analysis\OpenAiAnswerExtractor;
use App\Services\Analysis\ResponseAnalyzer;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fakes\FakeAnswerExtractor;

class ResponseAnalyzerTest extends TestCase
{
    private function analyzer(ExtractedAnswer $extracted): ResponseAnalyzer
    {
        return new ResponseAnalyzer(new FakeAnswerExtractor(fn () => $extracted), new HotelNameMatcher);
    }

    public function test_it_matches_the_hotel_fuzzily_and_ranks_it(): void
    {
        $extracted = new ExtractedAnswer(
            hotels: ['Maya Ubud Resort & Spa', 'ALAYA Ubud', 'The Kayon Jungle Resort', 'Komaneka at Bisma'],
            isRankedList: true,
            targetMentioned: true,
            targetSentiment: Sentiment::Positive,
            summary: 'Listed second as a family pick.',
        );

        $result = $this->analyzer($extracted)->analyze(
            answer: '...',
            hotelName: 'Alaya Resort Ubud',
            city: 'Ubud',
            competitors: ['Kayon Jungle Resort', 'Four Seasons Sayan'],
        );

        $this->assertTrue($result->hotelMentioned);
        $this->assertSame(2, $result->position);
        $this->assertSame(Sentiment::Positive, $result->sentiment);
        $this->assertSame(['Kayon Jungle Resort'], $result->competitorsMentioned);
        $this->assertSame(['Maya Ubud Resort & Spa', 'Komaneka at Bisma'], $result->otherHotelsMentioned);
        $this->assertSame('Listed second as a family pick.', $result->summary);
    }

    public function test_a_chinese_answer_naming_the_hotel_by_its_alternative_name_counts_as_a_mention(): void
    {
        $extracted = new ExtractedAnswer(['科莫乌玛度假村', '阿拉亚度假村', 'Kayon Jungle Resort'], true, false, Sentiment::Positive, '');
        $answer = "以下是乌布值得考虑的3家住宿：\n1. **科莫乌玛度假村**\n2. **阿拉亚度假村** - 泳池很漂亮。\n3. **Kayon Jungle Resort**";

        $result = $this->analyzer($extracted)->analyze($answer, 'Alaya Resort Ubud', 'Ubud', ['Kayon Jungle Resort'], ['阿拉亚度假村']);

        $this->assertTrue($result->hotelMentioned);
        $this->assertSame(2, $result->position);
        $this->assertSame(['Kayon Jungle Resort'], $result->competitorsMentioned);
        $this->assertSame(['科莫乌玛度假村'], $result->otherHotelsMentioned);
    }

    public function test_without_the_alternative_name_the_chinese_mention_is_missed(): void
    {
        $extracted = new ExtractedAnswer(['阿拉亚度假村'], false, false, Sentiment::NotMentioned, '');

        $result = $this->analyzer($extracted)->analyze('推荐**阿拉亚度假村**。', 'Alaya Resort Ubud', 'Ubud');

        $this->assertFalse($result->hotelMentioned);
    }

    public function test_not_mentioned_overrides_model_sentiment_and_position(): void
    {
        $extracted = new ExtractedAnswer(['Maya Ubud'], true, false, Sentiment::Positive, 'Recommends Maya instead.');

        $result = $this->analyzer($extracted)->analyze('Stay at Maya Ubud.', 'Alaya Resort Ubud', 'Ubud');

        $this->assertFalse($result->hotelMentioned);
        $this->assertNull($result->position);
        $this->assertSame(Sentiment::NotMentioned, $result->sentiment);
    }

    public function test_mention_found_in_text_when_model_misses_it(): void
    {
        // Model forgot the hotel in its list, but the answer text names it.
        $extracted = new ExtractedAnswer([], false, false, Sentiment::NotMentioned, '');

        $result = $this->analyzer($extracted)->analyze(
            'A quieter option is the alaya resort, close to the market.',
            'Alaya Resort Ubud',
            'Ubud',
        );

        $this->assertTrue($result->hotelMentioned);
        $this->assertNull($result->position, 'no position outside a ranked list');
        $this->assertSame(Sentiment::Neutral, $result->sentiment);
        $this->assertSame('Hotel mentioned.', $result->summary);
    }

    public function test_duplicate_names_in_the_list_do_not_shift_position(): void
    {
        $extracted = new ExtractedAnswer(
            ['Maya Ubud', 'Maya Ubud Resort', 'Alaya'], true, true, Sentiment::Neutral, 'x',
        );

        $result = $this->analyzer($extracted)->analyze('...', 'Alaya Resort Ubud', 'Ubud');

        $this->assertSame(2, $result->position);
        $this->assertSame(['Maya Ubud'], $result->otherHotelsMentioned);
    }

    public function test_openai_extractor_sends_strict_schema_and_parses_output(): void
    {
        $json = json_encode([
            'hotels' => ['Alaya Resort Ubud', ''],
            'is_ranked_list' => true,
            'target_mentioned' => true,
            'target_sentiment' => 'negative',
            'summary' => 'Mentioned as noisy.',
        ]);

        $client = new ClientFake([CreateResponse::fake([
            'output' => [[
                'type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $json, 'annotations' => []]],
            ]],
        ], strategy: OverrideStrategy::Replace)]);

        $extracted = (new OpenAiAnswerExtractor($client, 'gpt-analysis'))->extract('answer', 'Alaya', 'Ubud', ['Kayon']);

        $this->assertSame(['Alaya Resort Ubud'], $extracted->hotels);
        $this->assertSame(Sentiment::Negative, $extracted->targetSentiment);
        $this->assertTrue($extracted->isRankedList);
        $this->assertGreaterThan(0, $extracted->tokensIn);

        $client->assertSent(Responses::class, function (string $method, array $params): bool {
            $format = $params['text']['format'];

            return $params['model'] === 'gpt-analysis'
                && $format['type'] === 'json_schema'
                && $format['strict'] === true
                && $format['schema']['additionalProperties'] === false
                && $format['schema']['required'] === array_keys($format['schema']['properties'])
                && str_contains($params['input'], 'Kayon');
        });
    }

    public function test_invalid_json_from_analysis_model_throws(): void
    {
        $this->expectException(RuntimeException::class);

        OpenAiAnswerExtractor::fromJson('not json');
    }
}
