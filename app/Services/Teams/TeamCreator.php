<?php

namespace App\Services\Teams;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a team owned by the given user and makes it their current team.
 */
final class TeamCreator
{
    public function create(User $owner, string $name, Plan $plan = Plan::Trial): Team
    {
        return DB::transaction(function () use ($owner, $name, $plan): Team {
            $team = Team::create([
                'name' => $name,
                'plan' => $plan,
                'timezone' => (string) config('citevanta.default_timezone', 'Asia/Makassar'),
            ]);

            $team->addMember($owner, TeamRole::Owner);
            $owner->switchTeam($team);

            return $team;
        });
    }
}
