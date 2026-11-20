<?php

namespace App\Services\Dashboard;

/**
 * Headline numbers of one scan (optionally one engine).
 */
final readonly class ScanMetrics
{
    public function __construct(
        public int $answers,
        public int $mentions,
        public ?float $visibility,
        public ?float $averagePosition,
        public int $positive,
        public int $neutral,
        public int $negative,
        /** Share of the hotel's mentions that were positive. */
        public ?float $positivePct,
        /** Distinct domains cited. */
        public int $citedSources,
    ) {}
}
