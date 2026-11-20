<?php

namespace App\Policies;

use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Services\Plans\PlanGuard;

/**
 * Users only see and change hotels that belong to one of their teams.
 */
class HotelPolicy
{
    /** Abilities internal admins have on every hotel (support): viewing and scanning, not editing. */
    private const ADMIN_ABILITIES = ['view', 'scan'];

    public function __construct(private PlanGuard $plans) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin && in_array($ability, self::ADMIN_ABILITIES, true) ? true : null;
    }

    /**
     * Determine whether the user can list the team's hotels.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the hotel.
     */
    public function view(User $user, Hotel $hotel): bool
    {
        return $this->isTeamMember($user, $hotel);
    }

    /**
     * Determine whether the user can add a hotel to the team (within plan limits).
     */
    public function create(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team) && $this->plans->canAddHotel($team);
    }

    /**
     * Determine whether the user can edit the hotel's setup.
     */
    public function update(User $user, Hotel $hotel): bool
    {
        return $this->isTeamMember($user, $hotel);
    }

    /**
     * Determine whether the user can start a manual scan.
     */
    public function scan(User $user, Hotel $hotel): bool
    {
        return $this->isTeamMember($user, $hotel);
    }

    /**
     * Determine whether the user can delete the hotel.
     */
    public function delete(User $user, Hotel $hotel): bool
    {
        return $this->isTeamMember($user, $hotel) && $user->ownsTeam($hotel->team);
    }

    private function isTeamMember(User $user, Hotel $hotel): bool
    {
        return $hotel->team !== null && $user->belongsToTeam($hotel->team);
    }
}
