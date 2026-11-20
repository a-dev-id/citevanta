<?php

namespace App\Services\Scanning;

/** What happened during a scan, including failures that were skipped. */
final readonly class ScanSummary
{
    /**
     * @param  list<string>  $errors  short, secret-free error descriptions
     * @param  list<string>  $unpricedModels  models priced with the fallback rate
     */
    public function __construct(
        public int $attempted,
        public int $answered,
        public int $analyzed,
        public int $engineFailures,
        public int $analysisFailures,
        public float $costUsd,
        public array $errors = [],
        public array $unpricedModels = [],
    ) {}
}
