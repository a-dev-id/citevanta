<?php

namespace App\Services\Analysis;

use App\Enums\Sentiment;

/** Final, matched analysis of one answer (maps to response_analyses). */
final readonly class AnalysisResult
{
    /**
     * @param  list<string>  $competitorsMentioned  known competitor names (as configured) that appeared
     * @param  list<string>  $otherHotelsMentioned  other hotels named, as written in the answer
     */
    public function __construct(
        public bool $hotelMentioned,
        public ?int $position,
        public Sentiment $sentiment,
        public array $competitorsMentioned,
        public array $otherHotelsMentioned,
        public string $summary,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'hotel_mentioned' => $this->hotelMentioned,
            'position' => $this->position,
            'sentiment' => $this->sentiment,
            'competitors_mentioned' => $this->competitorsMentioned,
            'other_hotels_mentioned' => $this->otherHotelsMentioned,
            'summary' => $this->summary,
        ];
    }
}
