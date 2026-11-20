<?php

namespace App\Services\Hotels;

use App\Models\Hotel;
use App\Models\User;

/**
 * The hotel the user is working on: the last one they opened (kept in the
 * session) if it belongs to their current team, otherwise the team's first.
 */
final class CurrentHotel
{
    private const SESSION_KEY = 'current_hotel_id';

    public function get(User $user): ?Hotel
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return null;
        }

        $id = session(self::SESSION_KEY);

        return ($id ? $team->hotels()->find($id) : null)
            ?? $team->hotels()->orderBy('name')->first();
    }

    public function set(Hotel $hotel): void
    {
        session([self::SESSION_KEY => $hotel->id]);
    }
}
