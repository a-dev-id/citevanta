<?php

namespace App\Services\Scanning;

/**
 * One API call of a scan: a prompt, a run number and an engine.
 */
final readonly class PlannedCall
{
    public function __construct(
        public int $promptId,
        public int $runNumber,
        public string $engine,
    ) {}

    public function key(int $scanRunId): string
    {
        return "{$scanRunId}:{$this->promptId}:{$this->runNumber}:{$this->engine}";
    }
}
