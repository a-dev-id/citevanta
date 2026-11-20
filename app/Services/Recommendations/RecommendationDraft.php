<?php

namespace App\Services\Recommendations;

use App\Enums\Effort;
use App\Enums\Impact;
use App\Enums\RecommendationCategory;

/**
 * A recommendation as the rules build it from one scan's evidence.
 */
final class RecommendationDraft
{
    /**
     * @param  list<string>  $steps
     * @param  list<array{type: string, label: string, url: ?string}>  $evidence
     * @param  array{type: string, value: string|int}|null  $metric
     * @param  int  $strength  how much evidence (0-7), part of the priority score
     */
    public function __construct(
        public readonly string $key,
        public readonly RecommendationCategory $category,
        public readonly string $title,
        public readonly string $why,
        public readonly array $steps,
        public readonly string $where,
        public readonly Effort $effort,
        public readonly Impact $impact,
        public readonly array $evidence = [],
        public readonly ?array $metric = null,
        public readonly int $strength = 1,
    ) {}

    /**
     * 0-100: impact counts most, then how quick it is, then how much evidence.
     */
    public function priority(): int
    {
        return min(100, $this->impact->weight() * 25 + (4 - $this->effort->rank()) * 6 + max(0, min(7, $this->strength)));
    }

    /** Changes when the text the rules wrote changes (new numbers, new evidence). */
    public function hash(): string
    {
        return sha1(json_encode([$this->title, $this->why, $this->steps, $this->where], JSON_THROW_ON_ERROR));
    }
}
