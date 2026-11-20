<?php

namespace App\Services\Insights\Competitors;

use App\Enums\HotelHasFeature;
use App\Services\Insights\OpenAiJsonCaller;
use Illuminate\Support\Str;

/**
 * Competitor reasons with the analysis model (strict JSON schema).
 */
final class OpenAiCompetitorReasoner implements CompetitorReasoner
{
    public const MAX_REASONS = 8;

    /** Characters of each answer sent (keeps the call small and cheap). */
    private const ANSWER_CHARS = 3000;

    public function __construct(private readonly OpenAiJsonCaller $caller) {}

    public function explain(CompetitorReasonInput $input): CompetitorReasonResult
    {
        $response = $this->caller->call('competitor_reasons', self::schema(), self::instructions(), self::input($input));

        return new CompetitorReasonResult(
            reasons: self::reasonsFrom($response->data),
            summary: Str::limit(trim((string) ($response->data['summary'] ?? '')), 1000, ''),
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
            'required' => ['summary', 'reasons'],
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One sentence: why the competitors were recommended instead of the target hotel.'],
                'reasons' => [
                    'type' => 'array',
                    'description' => 'Features or evidence the answers used to recommend each competitor. At most '.self::MAX_REASONS.'.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['competitor', 'reason', 'quote', 'fact_key', 'hotel_has'],
                        'properties' => [
                            'competitor' => ['type' => 'string', 'description' => 'Exactly one of the given competitor names.'],
                            'reason' => ['type' => 'string', 'description' => 'A short feature or evidence phrase, 2-6 words, e.g. "kids club", "rice field view", "mentioned by Lonely Planet".'],
                            'quote' => ['type' => 'string', 'description' => 'The exact words from an answer that show this reason, at most 25 words.'],
                            'fact_key' => ['type' => 'string', 'description' => 'The hotel fact key this reason is about, exactly as given, or an empty string.'],
                            'hotel_has' => ['type' => 'string', 'enum' => HotelHasFeature::values(), 'description' => 'has_it only if a hotel fact clearly confirms it; lacks_it only if a fact clearly says the hotel does not have it; otherwise unknown.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function instructions(): string
    {
        return <<<'TXT'
        An AI travel assistant answered a traveller's question and recommended competitor hotels instead of the target hotel (or ranked them higher).
        Explain why, using only what the answers and their cited sources say. Do not use outside knowledge.
        Give concrete features or evidence (a facility, a view, a location, a price point, a source that recommends them), not generic praise.
        Then compare each reason with the target hotel's facts. Never guess whether the target hotel has something: use unknown when the facts do not say.
        TXT;
    }

    private static function input(CompetitorReasonInput $input): string
    {
        $facts = $input->facts === []
            ? '(no facts entered)'
            : implode("\n", array_map(fn ($key, $value) => "- {$key}: {$value}", array_keys($input->facts), $input->facts));

        $answers = [];
        foreach ($input->answers as $i => $answer) {
            $sources = collect($answer['citations'])
                ->map(fn ($c) => '- '.$c['domain'].($c['title'] ? ' ('.$c['title'].')' : ''))
                ->implode("\n") ?: '- none';
            $answers[] = 'Answer '.($i + 1)." ({$answer['engine']}):\n\"\"\"\n".Str::limit($answer['text'], self::ANSWER_CHARS)."\n\"\"\"\nCited sources:\n{$sources}";
        }

        return "Target hotel: {$input->hotelName} ({$input->city})\n"
            .'Competitors that were recommended instead: '.implode('; ', $input->competitors)."\n"
            ."Traveller question: {$input->prompt}\n\n"
            ."Target hotel facts:\n{$facts}\n\n"
            .implode("\n\n", $answers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<CompetitorReason>
     */
    public static function reasonsFrom(array $data): array
    {
        $reasons = [];

        foreach (array_slice((array) ($data['reasons'] ?? []), 0, self::MAX_REASONS) as $row) {
            if (! is_array($row) || trim((string) ($row['reason'] ?? '')) === '' || trim((string) ($row['competitor'] ?? '')) === '') {
                continue;
            }

            $reasons[] = new CompetitorReason(
                competitor: trim((string) $row['competitor']),
                reason: Str::limit(trim((string) $row['reason']), 120, ''),
                quote: Str::limit(trim((string) ($row['quote'] ?? '')), 300, ''),
                factKey: trim((string) ($row['fact_key'] ?? '')) ?: null,
                hotelHas: HotelHasFeature::tryFrom((string) ($row['hotel_has'] ?? '')) ?? HotelHasFeature::Unknown,
            );
        }

        return $reasons;
    }
}
