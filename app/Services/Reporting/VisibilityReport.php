<?php

namespace App\Services\Reporting;

final readonly class VisibilityReport
{
    /**
     * @param  array<string, array{answers: int, mentions: int, visibility: float, avg_position: ?float, positive: int, neutral: int, negative: int, not_mentioned: int}>  $perPersona
     * @param  array<string, int>  $sentimentCounts
     * @param  list<array{name: string, is_target: bool, mentions: int, visibility: float, share_of_voice: float}>  $shareOfVoice
     * @param  list<array{domain: string, citations: int}>  $topDomains
     */
    public function __construct(
        public string $hotelName,
        public int $totalAnswers,
        public int $mentions,
        public float $visibility,
        public array $perPersona,
        public ?float $averagePosition,
        public array $sentimentCounts,
        public array $shareOfVoice,
        public array $topDomains,
        public int $tokensIn,
        public int $tokensOut,
        public int $webSearchCalls,
        public float $costUsd,
    ) {}
}
