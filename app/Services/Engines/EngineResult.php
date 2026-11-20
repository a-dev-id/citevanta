<?php

namespace App\Services\Engines;

final readonly class EngineResult
{
    /**
     * @param  list<CitationData>  $citations
     */
    public function __construct(
        public string $text,
        public array $citations = [],
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $webSearchCalls = 0,
    ) {}
}
