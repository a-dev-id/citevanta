<?php

namespace App\Services\Budget;

use App\Models\Team;
use App\Services\Plans\PlanCatalog;

/**
 * A paying team's AI cost in a month may not exceed TEAM_COST_CAP_PERCENT
 * of its plan price (USD price x hotels). Above it, manual scans and
 * content generation are refused; scheduled scans still run. Trials have
 * their own budget bucket; free (comped) teams are not capped.
 */
final class TeamCostCap
{
    public const MESSAGE = 'You have used this month\'s allowance for extra scans and content. Scheduled scans keep running, and the allowance resets on the 1st.';

    public function __construct(
        private readonly AiSpend $spend,
        private readonly PlanCatalog $catalog,
    ) {}

    public function cap(Team $team): ?float
    {
        if (! $team->plan->isPaid() || $team->is_comped) {
            return null;
        }

        $monthly = $this->catalog->monthly($team->plan)['usd'] ?? null;

        return $monthly === null ? null : $monthly * max(1, (int) $team->hotel_quantity) * (float) config('citevanta.budget.team_cost_cap_percent', 30) / 100;
    }

    public function exceeded(Team $team, float $estimate = 0.0): bool
    {
        $cap = $this->cap($team);

        return $cap !== null && $this->spend->teamSpent($team) + $estimate > $cap;
    }
}
