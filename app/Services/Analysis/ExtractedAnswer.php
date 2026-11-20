<?php

namespace App\Services\Analysis;

use App\Enums\Sentiment;

/** Raw facts the analysis model extracted from one answer. */
final readonly class ExtractedAnswer
{
    /**
     * @param  list<string>  $hotels  every property named, in order of first appearance
     */
    public function __construct(
        public array $hotels,
        public bool $isRankedList,
        public bool $targetMentioned,
        public Sentiment $targetSentiment,
        public string $summary,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
    ) {}
}
