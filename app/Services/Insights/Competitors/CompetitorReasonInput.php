<?php

namespace App\Services\Insights\Competitors;

/**
 * One prompt's answers where competitors beat the hotel.
 */
final class CompetitorReasonInput
{
    /**
     * @param  array<string, string>  $facts  fact key => value (only facts with a value)
     * @param  list<string>  $competitors  competitors that beat the hotel
     * @param  list<array{engine: string, text: string, citations: list<array{domain: string, title: ?string}>}>  $answers
     */
    public function __construct(
        public readonly string $hotelName,
        public readonly string $city,
        public readonly string $prompt,
        public readonly array $facts,
        public readonly array $competitors,
        public readonly array $answers,
    ) {}
}
