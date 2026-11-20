<?php

namespace App\Services\Scanning;

use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\ScanRun;
use Illuminate\Support\Collection;

final readonly class PreparedScan
{
    /**
     * @param  Collection<int, Prompt>  $prompts
     * @param  list<string>  $competitors
     */
    public function __construct(
        public Hotel $hotel,
        public ScanRun $scanRun,
        public Collection $prompts,
        public array $competitors,
        public int $runs,
        public string $requestedLanguage,
        public string $promptLanguage,
    ) {}

    public function totalCalls(): int
    {
        return $this->prompts->count() * $this->runs;
    }
}
