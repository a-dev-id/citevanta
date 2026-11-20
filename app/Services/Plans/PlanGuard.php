<?php

namespace App\Services\Plans;

use App\Enums\Plan;
use App\Models\Hotel;
use App\Models\Team;
use App\Services\Engines\EngineManager;

/**
 * Answers "is this allowed on the team's plan?" for hotels, prompts,
 * competitors, languages, engines and scans.
 */
final class PlanGuard
{
    public function __construct(private readonly EngineManager $engines) {}

    public function canAddHotel(Team $team): bool
    {
        return $team->hotels()->count() < $team->limits()->hotels;
    }

    public function promptLimit(Hotel $hotel): int
    {
        return $this->teamLimits($hotel)->promptsPerHotel;
    }

    public function competitorLimit(Hotel $hotel): int
    {
        return $this->teamLimits($hotel)->competitors;
    }

    public function languageLimit(Hotel $hotel): int
    {
        return $this->teamLimits($hotel)->languages;
    }

    public function runsPerPrompt(Hotel $hotel): int
    {
        return $this->teamLimits($hotel)->runsPerPrompt;
    }

    /**
     * Engines to scan with: allowed by the plan and actually implemented.
     *
     * @return list<string>
     */
    public function engines(Hotel $hotel): array
    {
        return $this->engines->usable($this->teamLimits($hotel)->engines);
    }

    /**
     * Whether a scan may start for this hotel at all (not about timing).
     */
    public function canScan(Hotel $hotel): bool
    {
        $team = $hotel->team;

        return $team !== null
            && ! $team->isReadOnly()
            && $team->limits()->scansPerMonth > 0
            && $this->engines($hotel) !== [];
    }

    /**
     * Hotels without a team (created by scan:test) get the Starter limits.
     */
    private function teamLimits(Hotel $hotel): PlanLimits
    {
        return $hotel->team?->limits() ?? PlanLimits::for(Plan::Starter);
    }
}
