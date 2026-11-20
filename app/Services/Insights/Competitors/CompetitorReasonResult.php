<?php

namespace App\Services\Insights\Competitors;

final class CompetitorReasonResult
{
    /**
     * @param  list<CompetitorReason>  $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly string $summary,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {}
}
