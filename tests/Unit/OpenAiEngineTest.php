<?php

namespace Tests\Unit;

use App\Services\Engines\CitationData;
use App\Services\Engines\OpenAiEngine;
use OpenAI\Resources\Responses;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;

class OpenAiEngineTest extends TestCase
{
    private function payload(): array
    {
        return [
            'output_text' => 'Try Hotel A or Hotel B.',
            'output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                ['type' => 'web_search_call', 'id' => 'ws_2', 'status' => 'completed'],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Try Hotel A or Hotel B.',
                        'annotations' => [
                            ['type' => 'url_citation', 'url' => 'https://www.tripadvisor.com/Hotel_A?utm_source=openai', 'title' => 'Hotel A Reviews', 'start_index' => 0, 'end_index' => 5],
                            ['type' => 'url_citation', 'url' => 'https://www.tripadvisor.com/Hotel_A?utm_source=openai', 'title' => 'Duplicate', 'start_index' => 6, 'end_index' => 9],
                            ['type' => 'url_citation', 'url' => 'https://booking.com/b?lang=en&utm_source=chatgpt.com', 'title' => '', 'start_index' => 10, 'end_index' => 12],
                            ['type' => 'file_citation', 'file_id' => 'f_1'],
                        ],
                    ]],
                ],
            ],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ];
    }

    public function test_it_extracts_text_citations_tokens_and_search_calls(): void
    {
        $result = OpenAiEngine::fromResponseArray($this->payload());

        $this->assertSame('Try Hotel A or Hotel B.', $result->text);
        $this->assertSame(1200, $result->tokensIn);
        $this->assertSame(300, $result->tokensOut);
        $this->assertSame(2, $result->webSearchCalls);
        $this->assertCount(2, $result->citations, 'duplicates and non-url annotations are dropped');

        $this->assertSame('https://www.tripadvisor.com/Hotel_A', $result->citations[0]->url);
        $this->assertSame('tripadvisor.com', $result->citations[0]->domain);
        $this->assertSame('Hotel A Reviews', $result->citations[0]->title);

        $this->assertSame('https://booking.com/b?lang=en', $result->citations[1]->url);
        $this->assertNull($result->citations[1]->title);
    }

    public function test_citation_keeps_unrelated_query_params(): void
    {
        $citation = CitationData::fromUrl('https://Example.com/x?utm_source=newsletter');

        $this->assertSame('https://Example.com/x?utm_source=newsletter', $citation->url);
        $this->assertSame('example.com', $citation->domain);
    }

    public function test_ask_sends_web_search_tool_through_responses_api(): void
    {
        $client = new ClientFake([CreateResponse::fake()]);
        $engine = new OpenAiEngine($client, 'gpt-test');

        $result = $engine->ask('best hotel in Ubud');

        $this->assertNotSame('', $result->text);
        $this->assertSame(1, $result->webSearchCalls);
        $this->assertNotEmpty($result->citations);

        $client->assertSent(Responses::class, function (string $method, array $params): bool {
            return $method === 'create'
                && $params['model'] === 'gpt-test'
                && $params['input'] === 'best hotel in Ubud'
                && $params['tools'] === [['type' => 'web_search']];
        });
    }
}
