<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Members can see their team's settings.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Only owners can rename the team or change its timezone.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }

    /**
     * Only owners see and change the subscription (Settings > Billing).
     */
    public function manageBilling(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }
}
