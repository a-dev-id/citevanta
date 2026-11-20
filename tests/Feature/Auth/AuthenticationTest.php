<?php

namespace Tests\Feature\Auth;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_screens_render_in_the_auth_layout(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password/some-token'] as $url) {
            $this->get($url)->assertOk()->assertSee('cv-auth', false);
        }
    }

    public function test_registration_creates_a_user_who_owns_a_trial_team(): void
    {
        $this->freezeSecond();

        $this->post('/register', [
            'name' => 'Made Wirawan',
            'email' => 'made@example.com',
            'team_name' => 'Ubud Resorts Group',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'made@example.com')->sole();
        $team = $user->currentTeam;

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ubud Resorts Group', $team->name);
        $this->assertSame(Plan::Trial, $team->plan);
        $this->assertTrue($team->trial_ends_at->equalTo(now()->addDays(7)));
        $this->assertSame('Asia/Makassar', $team->timezone);
        $this->assertSame(TeamRole::Owner, $user->teamRole($team));
        $this->assertFalse($user->is_admin);
    }

    public function test_team_name_defaults_to_the_users_name(): void
    {
        $this->post('/register', [
            'name' => 'Ayu',
            'email' => 'ayu@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame("Ayu's Team", User::where('email', 'ayu@example.com')->sole()->currentTeam->name);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->from('/register')->post('/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertRedirect('/register')->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertGuest();
    }

    public function test_users_can_log_in_and_out(): void
    {
        $user = User::factory()->withTeam()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->withTeam()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unverified_users_are_sent_to_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->withTeam()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/email/verify');
        $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee('Verify your email');
    }

    public function test_password_reset_link_is_sent(): void
    {
        Notification::fake();
        $user = User::factory()->withTeam()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_guests_cannot_open_the_app(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
