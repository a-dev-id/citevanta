<?php

namespace App\Services\Insights;

/**
 * Parsed JSON from one insight AI call, with its token usage.
 */
final class AiJsonResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly string $model,
    ) {}
}
