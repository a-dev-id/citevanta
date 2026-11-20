<?php

namespace App\Services\Insights\Accuracy;

use App\Enums\AccuracyVerdict;

/**
 * One thing an answer says about the hotel, checked against its facts.
 */
final class AccuracyClaim
{
    public function __construct(
        public readonly string $factKey,
        public readonly string $aiClaim,
        public readonly string $aiValue,
        public readonly ?string $trueValue,
        public readonly AccuracyVerdict $verdict,
        public readonly string $quote,
    ) {}

    /** @return array{fact_key: string, ai_claim: string, ai_value: string, true_value: ?string, verdict: string, quote: string} */
    public function toArray(): array
    {
        return [
            'fact_key' => $this->factKey,
            'ai_claim' => $this->aiClaim,
            'ai_value' => $this->aiValue,
            'true_value' => $this->trueValue,
            'verdict' => $this->verdict->value,
            'quote' => $this->quote,
        ];
    }
}
