<?php

namespace App\Services\Insights\Accuracy;

use App\Enums\AccuracyVerdict;
use App\Services\Insights\OpenAiJsonCaller;

/**
 * Accuracy check with the analysis model (strict JSON schema).
 */
final class OpenAiAccuracyChecker implements AccuracyChecker
{
    public const MAX_CLAIMS = 12;

    public function __construct(private readonly OpenAiJsonCaller $caller) {}

    public function check(AccuracyInput $input): AccuracyResult
    {
        $response = $this->caller->call('hotel_accuracy_check', self::schema(), self::instructions(), self::input($input));

        return new AccuracyResult(
            claims: self::claimsFrom($response->data),
            tokensIn: $response->tokensIn,
            tokensOut: $response->tokensOut,
            model: $response->model,
        );
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['claims'],
            'properties' => [
                'claims' => [
                    'type' => 'array',
                    'description' => 'Checkable claims the answer makes about the target hotel only. At most '.self::MAX_CLAIMS.'.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['fact_key', 'ai_claim', 'ai_value', 'true_value', 'verdict', 'quote'],
                        'properties' => [
                            'fact_key' => ['type' => 'string', 'description' => 'Exactly one of the given fact keys when the claim is about it; otherwise a short topic name (2-4 words, e.g. "Kids club", "Airport transfer").'],
                            'ai_claim' => ['type' => 'string', 'description' => 'What the answer says, as one short sentence.'],
                            'ai_value' => ['type' => 'string', 'description' => 'The claimed value in as few words as possible, e.g. "has a kids club", "USD 80-120", "45 minutes".'],
                            'true_value' => ['type' => 'string', 'description' => 'The value from the hotel facts, or an empty string when the facts do not cover it.'],
                            'verdict' => ['type' => 'string', 'enum' => AccuracyVerdict::values()],
                            'quote' => ['type' => 'string', 'description' => 'The exact words from the answer, at most 25 words.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function instructions(): string
    {
        return <<<'TXT'
        You check what an AI travel assistant said about one specific hotel against that hotel's own facts.
        List only claims about the target hotel, never about other hotels.
        Only include checkable facts: prices, location and distances, facilities and services the hotel has or lacks, room types, policies, times.
        Ignore opinions and generic praise ("great service", "beautiful").
        Verdicts:
        - correct: the facts confirm the claim.
        - wrong: the facts contradict the claim.
        - outdated: the claim describes an older situation that the facts show has changed (e.g. an old price, a closed or renovated facility).
        - not_in_facts: the facts do not cover the claim. Never guess.
        Use the facts exactly as given; do not invent facts.
        TXT;
    }

    private static function input(AccuracyInput $input): string
    {
        $facts = $input->facts === []
            ? '(no facts entered)'
            : implode("\n", array_map(fn ($key, $value) => "- {$key}: {$value}", array_keys($input->facts), $input->facts));

        return "Target hotel: {$input->hotelName} ({$input->city})\n\nHotel facts:\n{$facts}\n\nAnswer to check:\n\"\"\"\n{$input->answer}\n\"\"\"";
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<AccuracyClaim>
     */
    public static function claimsFrom(array $data): array
    {
        $claims = [];

        foreach (array_slice((array) ($data['claims'] ?? []), 0, self::MAX_CLAIMS) as $row) {
            if (! is_array($row) || trim((string) ($row['fact_key'] ?? '')) === '' || trim((string) ($row['ai_claim'] ?? '')) === '') {
                continue;
            }

            $claims[] = new AccuracyClaim(
                factKey: trim((string) $row['fact_key']),
                aiClaim: trim((string) $row['ai_claim']),
                aiValue: trim((string) ($row['ai_value'] ?? '')),
                trueValue: trim((string) ($row['true_value'] ?? '')) ?: null,
                verdict: AccuracyVerdict::tryFrom((string) ($row['verdict'] ?? '')) ?? AccuracyVerdict::NotInFacts,
                quote: trim((string) ($row['quote'] ?? '')),
            );
        }

        return $claims;
    }
}
