<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Services\Plans\PlanGuard;
use App\Services\Plans\PlanLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_limits_match_the_pricing_table(): void
    {
        $starter = PlanLimits::for(Plan::Starter);
        $this->assertSame(
            [1, 30, 3, 2, ['openai', 'gemini'], 2, 1, 2],
            [$starter->hotels, $starter->promptsPerHotel, $starter->competitors, $starter->languages, $starter->engines, $starter->scansPerMonth, $starter->manualScansPerMonth, $starter->runsPerPrompt],
        );

        $pro = PlanLimits::for(Plan::Pro);
        $this->assertSame(
            [1, 50, 5, 4, ['openai', 'gemini', 'perplexity'], 2, 2, 2],
            [$pro->hotels, $pro->promptsPerHotel, $pro->competitors, $pro->languages, $pro->engines, $pro->scansPerMonth, $pro->manualScansPerMonth, $pro->runsPerPrompt],
        );

        $trial = PlanLimits::for(Plan::Trial);
        $this->assertSame([2, 10, 30], [$trial->contentGenerationsPerMonth, $starter->contentGenerationsPerMonth, $pro->contentGenerationsPerMonth]);
        // Trial lite: one small ChatGPT scan.
        $this->assertSame(
            [1, 15, 3, 1, ['openai'], 2, 0, 1],
            [$trial->hotels, $trial->promptsPerHotel, $trial->competitors, $trial->languages, $trial->engines, $trial->scansPerMonth, $trial->manualScansPerMonth, $trial->runsPerPrompt],
        );
        $this->assertSame(15.0, $starter->scanIntervalDays());
    }

    public function test_only_implemented_engines_are_used(): void
    {
        $hotel = Hotel::factory()->for(Team::factory()->plan(Plan::Pro))->create();

        $this->assertSame(['openai'], app(PlanGuard::class)->engines($hotel));
    }

    public function test_hotel_limit_blocks_a_second_hotel(): void
    {
        $user = User::factory()->withTeam()->create();
        $team = $user->currentTeam;

        $this->assertTrue($user->can('create', [Hotel::class, $team]));

        Hotel::factory()->for($team)->create();

        $this->assertFalse($user->can('create', [Hotel::class, $team]));
    }

    public function test_an_expired_trial_stops_scanning(): void
    {
        $active = Hotel::factory()->for(Team::factory()->plan(Plan::Trial))->create();
        $expired = Hotel::factory()->for(Team::factory()->expiredTrial())->create();

        $this->assertTrue(app(PlanGuard::class)->canScan($active));
        $this->assertFalse(app(PlanGuard::class)->canScan($expired));
    }

    public function test_new_teams_get_a_seven_day_trial(): void
    {
        $this->freezeSecond();

        $team = Team::create(['name' => 'New team']);

        $this->assertSame(Plan::Trial, $team->plan);
        $this->assertTrue($team->trial_ends_at->equalTo(now()->addDays(7)));
    }
}
