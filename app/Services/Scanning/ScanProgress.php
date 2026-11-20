<?php

namespace App\Services\Scanning;

use App\Models\ScanRun;

/**
 * How far a scan has got, and a rough time-left estimate based on the
 * answers collected per minute so far.
 */
final readonly class ScanProgress
{
    public function __construct(
        public int $answers,
        public int $failed,
        public int $planned,
        public ?float $answersPerMinute,
        public ?int $minutesLeft,
    ) {}

    public static function for(ScanRun $scanRun): self
    {
        $answers = $scanRun->responses()->count();
        $planned = $scanRun->plannedCalls();
        $done = $answers + (int) $scanRun->failed_count;

        $minutes = $scanRun->started_at ? max(0.5, $scanRun->started_at->diffInSeconds(now()) / 60) : null;
        $perMinute = $minutes !== null && $answers > 0 ? round($answers / $minutes, 1) : null;
        $left = $planned > 0 && $perMinute ? (int) ceil(max(0, $planned - $done) / $perMinute) : null;

        return new self($answers, (int) $scanRun->failed_count, $planned, $perMinute, $left);
    }
}
