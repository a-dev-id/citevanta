<?php

namespace App\Services\Budget;

use App\Enums\AiBucket;
use App\Enums\ScanTrigger;
use App\Models\ScanRun;
use App\Models\Team;

/**
 * Who an AI call is for: its budget bucket, and whether it is a paid
 * scheduled scan (the only work that continues above 90% of the total).
 */
final readonly class AiCallContext
{
    public function __construct(
        public AiBucket $bucket,
        public bool $isPaidScheduled = false,
        public ?Team $team = null,
        public ?int $hotelId = null,
        public ?ScanRun $scanRun = null,
    ) {}

    public static function forScan(ScanRun $scanRun): self
    {
        $scanRun->loadMissing('hotel.team');
        $team = $scanRun->hotel?->team;

        $bucket = match (true) {
            $scanRun->trigger === ScanTrigger::Audit => AiBucket::Audits,
            in_array($scanRun->trigger, [ScanTrigger::Admin, ScanTrigger::Cli], true), $team === null => AiBucket::Admin,
            $team->isOnTrial() => AiBucket::Trials,
            default => AiBucket::Paid,
        };

        return new self(
            bucket: $bucket,
            isPaidScheduled: $bucket === AiBucket::Paid && $scanRun->trigger === ScanTrigger::Scheduled,
            team: $team,
            hotelId: $scanRun->hotel_id,
            scanRun: $scanRun,
        );
    }

    /**
     * Content generation for a team's hotel (never "scheduled").
     */
    public static function forTeam(?Team $team, ?int $hotelId = null): self
    {
        $bucket = match (true) {
            $team === null => AiBucket::Admin,
            $team->isOnTrial() => AiBucket::Trials,
            default => AiBucket::Paid,
        };

        return new self(bucket: $bucket, team: $team, hotelId: $hotelId);
    }

    /** Admin tools: cost probes, tests from the console. */
    public static function admin(): self
    {
        return new self(bucket: AiBucket::Admin);
    }
}
