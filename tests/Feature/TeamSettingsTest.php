<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_rename_the_team_and_change_its_timezone(): void
    {
        $owner = User::factory()->withTeam()->create();

        $this->actingAs($owner)->put('/settings/team', ['name' => 'Bali Collection', 'timezone' => 'Asia/Jakarta'])
            ->assertRedirect('/settings/team');

        $team = $owner->currentTeam->fresh();
        $this->assertSame('Bali Collection', $team->name);
        $this->assertSame('Asia/Jakarta', $team->timezone);
    }

    public function test_members_cannot_change_team_settings(): void
    {
        $owner = User::factory()->withTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->addMember($member, TeamRole::Member);
        $member->switchTeam($owner->currentTeam);

        $this->actingAs($member)->get('/settings/team')->assertOk();
        $this->actingAs($member)->put('/settings/team', ['name' => 'Hijacked', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_timezone_must_be_valid(): void
    {
        $owner = User::factory()->withTeam()->create();

        $this->actingAs($owner)->put('/settings/team', ['name' => 'Team', 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_profile_can_be_updated_through_fortify(): void
    {
        $user = User::factory()->withTeam()->create();

        $this->actingAs($user)->get('/settings/profile')->assertOk();
        $this->actingAs($user)->put('/user/profile-information', ['name' => 'New Name', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
    }
}
