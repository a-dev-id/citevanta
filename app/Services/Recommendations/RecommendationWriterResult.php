<?php

namespace App\Services\Recommendations;

final class RecommendationWriterResult
{
    /**
     * @param  array<string, array{title: string, why: string, steps: list<string>, where: string}>  $items  by key
     */
    public function __construct(
        public readonly array $items,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {}
}
