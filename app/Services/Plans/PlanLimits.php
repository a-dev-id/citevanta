<?php

namespace App\Services\Plans;

use App\Enums\Plan;

/**
 * The limits of one plan (config: citevanta.plans).
 */
final readonly class PlanLimits
{
    /**
     * @param  list<string>  $engines
     */
    public function __construct(
        public int $hotels,
        public int $promptsPerHotel,
        public int $competitors,
        public int $languages,
        public array $engines,
        public int $scansPerMonth,
        public int $manualScansPerMonth,
        public int $runsPerPrompt,
        public int $contentGenerationsPerMonth = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  per-team values (teams.plan_limits), same keys as the config
     */
    public static function for(Plan $plan, array $overrides = []): self
    {
        $editable = array_flip((array) config('citevanta.editable_limits', []));
        $limits = array_intersect_key(array_filter($overrides, fn ($v) => $v !== null && $v !== ''), $editable)
            + (array) config("citevanta.plans.{$plan->value}", []);

        return new self(
            hotels: (int) ($limits['hotels'] ?? 0),
            promptsPerHotel: (int) ($limits['prompts_per_hotel'] ?? 0),
            competitors: (int) ($limits['competitors'] ?? 0),
            languages: (int) ($limits['languages'] ?? 1),
            engines: array_values((array) ($limits['engines'] ?? [])),
            scansPerMonth: (int) ($limits['scans_per_month'] ?? 0),
            manualScansPerMonth: (int) ($limits['manual_scans_per_month'] ?? 0),
            runsPerPrompt: max(1, (int) ($limits['runs_per_prompt'] ?? 1)),
            contentGenerationsPerMonth: (int) ($limits['content_generations_per_month'] ?? 0),
        );
    }

    public function withHotels(int $hotels): self
    {
        return new self(
            hotels: $hotels,
            promptsPerHotel: $this->promptsPerHotel,
            competitors: $this->competitors,
            languages: $this->languages,
            engines: $this->engines,
            scansPerMonth: $this->scansPerMonth,
            manualScansPerMonth: $this->manualScansPerMonth,
            runsPerPrompt: $this->runsPerPrompt,
            contentGenerationsPerMonth: $this->contentGenerationsPerMonth,
        );
    }

    /**
     * Days between scheduled scans, e.g. 2 scans per month = every 15 days.
     */
    public function scanIntervalDays(): float
    {
        return $this->scansPerMonth > 0 ? 30 / $this->scansPerMonth : INF;
    }
}
