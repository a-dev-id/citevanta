<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use App\Services\Scanning\ManualScanGate;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManualScanAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private function teamWithHotel(Plan $plan): array
    {
        $team = Team::factory()->plan($plan)->create(['timezone' => 'Asia/Makassar']);
        $user = User::factory()->withTeam($team)->create();

        return [$user, Hotel::factory()->for($team)->create()];
    }

    private function manualScan(Hotel $hotel, CarbonInterface $at, ScanTrigger $trigger = ScanTrigger::Manual): ScanRun
    {
        return ScanRun::factory()->for($hotel)->create([
            'trigger' => $trigger,
            'status' => ScanStatus::Completed,
            'created_at' => $at,
            'started_at' => $at,
            'finished_at' => $at->copy()->addMinutes(10),
        ]);
    }

    private function check(Hotel $hotel, User $user)
    {
        return app(ManualScanGate::class)->check($hotel->fresh(), $user);
    }

    public function test_starter_teams_get_one_manual_scan_per_month_and_trials_none(): void
    {
        [$trialUser, $trialHotel] = $this->teamWithHotel(Plan::Trial);
        $this->assertFalse($this->check($trialHotel, $trialUser)->allowed);
        $this->assertSame(0, $this->check($trialHotel, $trialUser)->limit);

        foreach ([Plan::Starter] as $plan) {
            $this->travelTo(Carbon::parse('2026-11-10 03:00', 'UTC'));
            [$user, $hotel] = $this->teamWithHotel($plan);

            $this->assertTrue($this->check($hotel, $user)->allowed, $plan->value);

            $this->manualScan($hotel, now()->subDays(3));
            $check = $this->check($hotel, $user);

            $this->assertFalse($check->allowed, $plan->value);
            $this->assertSame(1, $check->used);
            $this->assertSame(1, $check->limit);
        }
    }

    public function test_pro_teams_get_two_and_see_the_used_up_message(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Pro);

        $this->manualScan($hotel, now()->subDays(10));
        $this->assertTrue($this->check($hotel, $user)->allowed);

        $this->manualScan($hotel, now()->subDays(2));
        $check = $this->check($hotel, $user);

        $this->assertFalse($check->allowed);
        // Last completed scan finished 2 days ago, Pro = 2 scheduled scans per month = every 15 days.
        $this->assertSame('Manual scans used (2 of 2). Next scheduled scan: 3 Dec 2026.', $check->reason);
    }

    public function test_scheduled_and_setup_scans_do_not_use_the_allowance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Starter);

        $this->manualScan($hotel, now()->subDays(5), ScanTrigger::Scheduled);
        $this->manualScan($hotel, now()->subDays(4), ScanTrigger::Setup);

        $this->assertSame(0, $this->check($hotel, $user)->used);
        $this->assertTrue($this->check($hotel, $user)->allowed);
    }

    public function test_the_allowance_resets_on_the_first_of_the_month_in_the_team_timezone(): void
    {
        // Used on 30 Nov, 10:00 Makassar time.
        $this->travelTo(Carbon::parse('2026-11-30 02:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Starter);
        $this->manualScan($hotel, now());

        // 30 Nov 15:59 UTC = 30 Nov 23:59 in Makassar (UTC+8): still November.
        $this->travelTo(Carbon::parse('2026-11-30 15:59', 'UTC'));
        $this->assertSame(1, $this->check($hotel, $user)->used);
        $this->assertFalse($this->check($hotel, $user)->allowed);

        // 30 Nov 16:00 UTC = 1 Dec 00:00 in Makassar: new month, allowance is back
        // (and the 24-hour cooldown from 02:00 UTC has also... not passed yet).
        $this->travelTo(Carbon::parse('2026-11-30 16:00', 'UTC'));
        $this->assertSame(0, $this->check($hotel, $user)->used);

        // Once the 24 hours since the last manual scan have passed, it is allowed.
        $this->travelTo(Carbon::parse('2026-12-01 02:00', 'UTC'));
        $this->assertTrue($this->check($hotel, $user)->allowed);
    }

    public function test_the_reset_follows_the_team_timezone_setting(): void
    {
        $this->travelTo(Carbon::parse('2026-11-30 20:00', 'UTC')); // already 1 Dec in Makassar
        [$user, $hotel] = $this->teamWithHotel(Plan::Starter);
        $this->manualScan($hotel, Carbon::parse('2026-11-29 10:00', 'UTC'));

        $this->assertSame(0, $this->check($hotel, $user)->used);

        $hotel->team->update(['timezone' => 'UTC']); // still 30 Nov in UTC
        $this->assertSame(1, $this->check($hotel, $user)->used);
    }

    public function test_one_manual_scan_per_hotel_every_24_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Pro);
        $this->manualScan($hotel, now()->subHours(23));

        $check = $this->check($hotel, $user);
        $this->assertFalse($check->allowed);
        $this->assertStringContainsString('One manual scan per hotel every 24 hours', $check->reason);

        $this->travel(1)->hours();
        $this->assertTrue($this->check($hotel, $user)->allowed);
    }

    public function test_admins_have_no_monthly_limit_and_do_not_use_the_teams_allowance(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Starter);
        $admin = User::factory()->admin()->create();

        $this->manualScan($hotel, now()->subDays(5));
        $this->assertFalse($this->check($hotel, $user)->allowed, 'team allowance used up');

        $adminCheck = $this->check($hotel, $admin);
        $this->assertTrue($adminCheck->allowed);
        $this->assertNull($adminCheck->limit);

        // A scan the admin starts is recorded as an admin scan: the team's count stays at 1.
        $this->manualScan($hotel, now()->subDays(2), ScanTrigger::Admin);
        $this->assertSame(1, $this->check($hotel, $user)->used);
    }

    public function test_admins_skip_the_24_hour_rule(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Pro);
        $admin = User::factory()->admin()->create();

        $this->manualScan($hotel, now()->subHours(2), ScanTrigger::Admin);

        $this->assertTrue($this->check($hotel, $admin)->allowed);
        $this->assertStringContainsString('24 hours', $this->check($hotel, $user)->reason, 'team members still wait');
    }

    public function test_a_running_scan_blocks_everyone_including_admins(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 03:00', 'UTC'));
        [$user, $hotel] = $this->teamWithHotel(Plan::Pro);
        $admin = User::factory()->admin()->create();

        ScanRun::factory()->for($hotel)->create(['status' => ScanStatus::Running, 'finished_at' => null]);

        foreach ([$user, $admin] as $who) {
            $check = $this->check($hotel, $who);
            $this->assertFalse($check->allowed);
            $this->assertSame('A scan is already running for this hotel.', $check->reason);
        }
    }

    public function test_admins_can_scan_when_a_trial_has_expired(): void
    {
        $team = Team::factory()->expiredTrial()->create();
        $hotel = Hotel::factory()->for($team)->create();

        $this->assertTrue($this->check($hotel, User::factory()->admin()->create())->allowed);
    }

    public function test_an_expired_trial_blocks_manual_scans_for_team_members(): void
    {
        $team = Team::factory()->expiredTrial()->create();
        $user = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create();

        $check = $this->check($hotel, $user);

        $this->assertFalse($check->allowed);
        $this->assertStringContainsString('trial has ended', $check->reason);
    }
}
