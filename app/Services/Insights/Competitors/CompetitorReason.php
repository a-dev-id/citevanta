<?php

namespace App\Services\Insights\Competitors;

use App\Enums\HotelHasFeature;

/**
 * One reason the AI gave for recommending a competitor.
 */
final class CompetitorReason
{
    public function __construct(
        public readonly string $competitor,
        public readonly string $reason,
        public readonly string $quote,
        public readonly ?string $factKey,
        public readonly HotelHasFeature $hotelHas,
    ) {}

    /** @return array{competitor: string, reason: string, quote: string, fact_key: ?string, hotel_has: string} */
    public function toArray(): array
    {
        return [
            'competitor' => $this->competitor,
            'reason' => $this->reason,
            'quote' => $this->quote,
            'fact_key' => $this->factKey,
            'hotel_has' => $this->hotelHas->value,
        ];
    }
}
