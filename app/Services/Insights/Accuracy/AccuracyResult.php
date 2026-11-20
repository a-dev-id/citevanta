<?php

namespace App\Services\Insights\Accuracy;

final class AccuracyResult
{
    /**
     * @param  list<AccuracyClaim>  $claims
     */
    public function __construct(
        public readonly array $claims,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {}
}
