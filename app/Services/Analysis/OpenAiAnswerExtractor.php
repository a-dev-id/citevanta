<?php

namespace App\Services\Analysis;

use App\Enums\Sentiment;
use JsonException;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

/**
 * Uses the Responses API with Structured Outputs (strict JSON schema).
 */
final class OpenAiAnswerExtractor implements AnswerExtractor
{
    public function __construct(
        private readonly ClientContract $client,
        private readonly string $model,
        // Cost limits (OpenAiLimits::analysis()): no tools, capped output.
        private readonly array $limits = [],
    ) {}

    public function extract(string $answer, string $hotelName, string $city, array $competitors = [], array $alternativeNames = []): ExtractedAnswer
    {
        $response = $this->client->responses()->create([
            'model' => $this->model,
            'instructions' => $this->instructions(),
            'input' => $this->input($answer, $hotelName, $city, $competitors, $alternativeNames),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'hotel_answer_analysis',
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
        ] + $this->limits);

        $data = $response->toArray();

        return self::fromJson(
            (string) ($data['output_text'] ?? ''),
            (int) ($data['usage']['input_tokens'] ?? 0),
            (int) ($data['usage']['output_tokens'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['hotels', 'is_ranked_list', 'target_mentioned', 'target_sentiment', 'summary'],
            'properties' => [
                'hotels' => [
                    'type' => 'array',
                    'description' => 'Every hotel, resort or other accommodation property named in the answer, in the order they first appear, written exactly as in the answer. No duplicates.',
                    'items' => ['type' => 'string'],
                ],
                'is_ranked_list' => [
                    'type' => 'boolean',
                    'description' => 'True if the answer recommends several properties as a list or ranking.',
                ],
                'target_mentioned' => [
                    'type' => 'boolean',
                    'description' => 'True if the target hotel is named in the answer, even with a slightly different spelling.',
                ],
                'target_sentiment' => [
                    'type' => 'string',
                    'enum' => Sentiment::values(),
                    'description' => 'How the answer portrays the target hotel. not_mentioned if it is not named.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One sentence: how the answer treats the target hotel, or what it recommends instead.',
                ],
            ],
        ];
    }

    public static function fromJson(string $json, int $tokensIn = 0, int $tokensOut = 0): ExtractedAnswer
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Analysis model returned invalid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data) || ! isset($data['hotels'], $data['target_sentiment'])) {
            throw new RuntimeException('Analysis model returned JSON without the expected fields.');
        }

        return new ExtractedAnswer(
            hotels: array_values(array_filter(array_map('strval', (array) $data['hotels']), fn ($h) => trim($h) !== '')),
            isRankedList: (bool) ($data['is_ranked_list'] ?? false),
            targetMentioned: (bool) ($data['target_mentioned'] ?? false),
            targetSentiment: Sentiment::tryFrom((string) $data['target_sentiment']) ?? Sentiment::Neutral,
            summary: trim((string) ($data['summary'] ?? '')),
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
        );
    }

    private function instructions(): string
    {
        return <<<'TXT'
        You analyse answers that an AI travel assistant gave to a traveller.
        Extract facts only from the answer text. Do not add hotels that are not in it.
        Count only accommodation properties as hotels (not restaurants, beaches, tours or booking sites).
        TXT;
    }

    /**
     * @param  list<string>  $competitors
     * @param  list<string>  $alternativeNames
     */
    private function input(string $answer, string $hotelName, string $city, array $competitors, array $alternativeNames = []): string
    {
        $known = $competitors === [] ? 'none' : implode('; ', $competitors);
        $also = $alternativeNames === [] ? '' : "\nThe target hotel may also be written as: ".implode('; ', $alternativeNames);

        return "Target hotel: {$hotelName} ({$city}){$also}\nKnown competitors: {$known}\n\nAnswer to analyse (it may be in any language):\n\"\"\"\n{$answer}\n\"\"\"";
    }
}
