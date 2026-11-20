<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Livewire\Hotels\HotelDashboard;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class ScanNowButtonTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function dashboard(Hotel $hotel, User $user)
    {
        return Livewire::actingAs($user)->test(HotelDashboard::class, ['hotel' => $hotel]);
    }

    private function manualScan(Hotel $hotel, int $hoursAgo, ScanTrigger $trigger = ScanTrigger::Manual): void
    {
        $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => $trigger, 'status' => ScanStatus::Completed,
            'created_at' => now()->subHours($hoursAgo), 'started_at' => now()->subHours($hoursAgo), 'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    public function test_allowed_shows_the_allowance_and_starts_a_scan_after_confirming(): void
    {
        $hotel = $this->scannableHotel(plan: Plan::Pro);
        $user = User::factory()->withTeam($hotel->team)->create();

        $this->dashboard($hotel, $user)
            ->assertSee('2 of 2 manual scans left this month')
            ->assertSeeHtml('wire:confirm="Start a scan of')
            ->call('scanNow')
            ->assertSet('error', null)
            ->assertSee('Scan started.')
            ->assertDispatched('scan-started');

        $this->assertSame(ScanTrigger::Manual, $hotel->scanRuns()->sole()->trigger);
        Queue::assertPushed(ScanHotelJob::class);
    }

    public function test_disabled_while_a_scan_is_running(): void
    {
        $hotel = $this->scannableHotel();
        $user = User::factory()->withTeam($hotel->team)->create();
        $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Scheduled, 'status' => ScanStatus::Running, 'started_at' => now()]);

        $this->dashboard($hotel, $user)
            ->assertSee('Scan now unavailable: A scan is already running for this hotel.')
            ->call('scanNow')
            ->assertSet('error', 'A scan is already running for this hotel.');

        $this->assertSame(1, $hotel->scanRuns()->count());
    }

    public function test_disabled_by_the_24_hour_rule_with_the_time_it_becomes_available(): void
    {
        $hotel = $this->scannableHotel(plan: Plan::Pro);
        $user = User::factory()->withTeam($hotel->team)->create();
        $this->manualScan($hotel, 3);

        $this->dashboard($hotel, $user)
            ->assertSee('One manual scan per hotel every 24 hours. Next manual scan:');
    }

    public function test_disabled_when_the_monthly_allowance_is_used(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        $hotel = $this->scannableHotel(plan: Plan::Pro);
        $user = User::factory()->withTeam($hotel->team)->create();
        $this->manualScan($hotel, 100);
        $this->manualScan($hotel, 50);

        $this->dashboard($hotel, $user)
            ->assertSee('Manual scans used (2 of 2). Next scheduled scan:')
            ->assertSeeHtml('disabled');
    }

    public function test_admins_have_no_limit(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        $hotel = $this->scannableHotel(plan: Plan::Starter);
        $this->manualScan($hotel, 3);
        $admin = User::factory()->admin()->create();

        $this->dashboard($hotel, $admin)
            ->assertSee('Admin: no manual scan limit')
            ->call('scanNow')
            ->assertSet('error', null);

        $this->assertSame(ScanTrigger::Admin, $hotel->scanRuns()->latest('id')->first()->trigger);
    }
}
