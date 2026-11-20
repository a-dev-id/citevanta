<?php

namespace App\Services\Recommendations;

use App\Services\Insights\OpenAiJsonCaller;
use Illuminate\Support\Str;

/**
 * Recommendation wording with the analysis model (strict JSON schema).
 */
final class OpenAiRecommendationWriter implements RecommendationWriter
{
    public function __construct(private readonly OpenAiJsonCaller $caller) {}

    public function write(string $hotelName, string $city, array $facts, array $items): RecommendationWriterResult
    {
        $factsText = $facts === []
            ? '(no facts entered)'
            : implode("\n", array_map(fn ($key, $value) => "- {$key}: {$value}", array_keys($facts), $facts));

        $input = "Hotel: {$hotelName} ({$city})\n\nHotel facts:\n{$factsText}\n\nRecommendations to rewrite (JSON):\n"
            .json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->caller->call('hotel_recommendations', self::schema(), self::instructions(), $input);
        $keys = array_column($items, 'key');
        $result = [];

        foreach ((array) ($response->data['recommendations'] ?? []) as $row) {
            if (! is_array($row) || ! in_array($row['key'] ?? null, $keys, true)) {
                continue;
            }

            $steps = array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) ($row['steps'] ?? [])), fn ($s) => $s !== ''));

            if (trim((string) ($row['title'] ?? '')) === '' || trim((string) ($row['why'] ?? '')) === '' || $steps === []) {
                continue;
            }

            $result[$row['key']] = [
                'title' => Str::limit(trim((string) $row['title']), 255, ''),
                'why' => trim((string) $row['why']),
                'steps' => array_slice($steps, 0, 8),
                'where' => Str::limit(trim((string) ($row['where'] ?? '')), 255, ''),
            ];
        }

        return new RecommendationWriterResult($result, $response->tokensIn, $response->tokensOut, $response->model);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['recommendations'],
            'properties' => [
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'title', 'why', 'steps', 'where'],
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'The key of the recommendation, unchanged.'],
                            'title' => ['type' => 'string', 'description' => 'Short action title, at most 12 words.'],
                            'why' => ['type' => 'string', 'description' => 'Why this matters for this hotel, 1-3 sentences, keeping every number from the input.'],
                            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3-6 numbered steps a non-technical hotel team can follow (without the numbers).'],
                            'where' => ['type' => 'string', 'description' => 'Which page or platform, short.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function instructions(): string
    {
        return <<<'TXT'
        You write action items for a hotel's marketing team about how AI assistants (like ChatGPT) recommend their hotel.
        Rewrite each recommendation so it is practical, specific to this hotel and easy for a non-technical team to follow.
        Rules:
        - Keep the meaning, the key, and every number and name from the input exactly.
        - Use only the hotel facts given. Never invent facilities, prices, distances, awards or anything else about the hotel.
        - No generic SEO advice and no jargon; say exactly what to do and where.
        - Keep the same language as the input (English).
        TXT;
    }
}
