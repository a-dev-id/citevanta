<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_can_access_their_own_teams_hotels(): void
    {
        $user = User::factory()->withTeam()->create();
        $hotel = Hotel::factory()->for($user->currentTeam)->create();

        $this->assertTrue($user->can('view', $hotel));
        $this->assertTrue($user->can('update', $hotel));
        $this->assertTrue($user->can('scan', $hotel));
    }

    public function test_users_cannot_access_other_teams_hotels(): void
    {
        $user = User::factory()->withTeam()->create();
        $otherHotel = Hotel::factory()->for(User::factory()->withTeam()->create()->currentTeam)->create();

        $this->assertFalse($user->can('view', $otherHotel));
        $this->assertFalse($user->can('update', $otherHotel));
        $this->assertFalse($user->can('scan', $otherHotel));
        $this->assertFalse($user->can('delete', $otherHotel));
    }

    public function test_hotels_without_a_team_are_not_visible_to_anyone(): void
    {
        $user = User::factory()->withTeam()->create();

        $this->assertFalse($user->can('view', Hotel::factory()->create(['team_id' => null])));
    }

    public function test_only_owners_can_delete_hotels(): void
    {
        $owner = User::factory()->withTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->addMember($member, TeamRole::Member);
        $hotel = Hotel::factory()->for($owner->currentTeam)->create();

        $this->assertTrue($owner->can('delete', $hotel));
        $this->assertTrue($member->can('view', $hotel));
        $this->assertFalse($member->can('delete', $hotel));
    }

    public function test_the_dashboard_only_lists_the_current_teams_hotels(): void
    {
        $user = User::factory()->withTeam()->create();
        Hotel::factory()->for($user->currentTeam)->create(['name' => 'Our Resort']);
        Hotel::factory()->for(User::factory()->withTeam()->create()->currentTeam)->create(['name' => 'Their Resort']);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Our Resort')->assertDontSee('Their Resort');
    }

    public function test_a_user_removed_from_their_current_team_falls_back_to_another_team(): void
    {
        $user = User::factory()->withTeam()->create();
        $first = $user->currentTeam;
        $second = User::factory()->withTeam()->create()->currentTeam;
        $second->addMember($user);

        $first->users()->detach($user);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame($second->id, $user->fresh()->current_team_id);
    }
}
