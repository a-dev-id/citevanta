<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_demo_and_can_run_twice_without_duplicates(): void
    {
        $other = User::factory()->withTeam()->create(); // not demo data: must survive

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', DemoSeeder::DEMO_EMAIL)->count());
        $this->assertTrue(User::where('email', DemoSeeder::ADMIN_EMAIL)->sole()->is_admin);
        $this->assertSame(1, Team::where('name', DemoSeeder::TEAM_NAME)->count());
        $this->assertSame(Plan::Pro, Team::where('name', DemoSeeder::TEAM_NAME)->sole()->plan);
        $this->assertNotNull($other->fresh());

        $hotel = Hotel::where('name', DemoSeeder::HOTEL_NAME)->sole();
        $this->assertTrue($hotel->isSetUp());
        $this->assertSame(3, $hotel->competitors()->count());
        $this->assertCount(2, $hotel->guest_markets);
        $this->assertGreaterThanOrEqual(5, $hotel->facts()->count());
        $this->assertSame(5, $hotel->prompts()->distinct()->count('persona'), 'all personas');

        $draft = Hotel::where('name', DemoSeeder::DRAFT_HOTEL_NAME)->sole();
        $this->assertFalse($draft->isSetUp());
    }

    public function test_three_completed_scans_over_six_weeks_with_rising_visibility(): void
    {
        $this->seed(DemoSeeder::class);

        $scans = Hotel::where('name', DemoSeeder::HOTEL_NAME)->sole()->scanRuns()->orderBy('started_at')->get();

        $this->assertCount(3, $scans);
        $this->assertTrue($scans->every(fn (ScanRun $s) => $s->status === ScanStatus::Completed && $s->answers_count > 0));
        $this->assertTrue($scans->first()->started_at->greaterThan(now()->subWeeks(6)->subDay()));
        $this->assertTrue($scans->first()->started_at->lessThan(now()->subWeeks(5)));
        $this->assertTrue($scans->last()->started_at->greaterThan(now()->subWeek()));

        $visibility = $scans->map(fn (ScanRun $s) => $s->visibilityScores()->whereNull('persona')->sole()->visibility_pct)->all();
        $this->assertSame($visibility, collect($visibility)->sort()->values()->all(), 'visibility improves scan to scan');
        $this->assertGreaterThan($visibility[0], $visibility[2]);
    }
}
