<?php

namespace Tests\Feature\Scans;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Models\User;
use App\Services\Scanning\ManualScanNotAllowed;
use App\Services\Scanning\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class StartManualScanTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_member_starts_a_manual_scan(): void
    {
        $hotel = $this->scannableHotel();
        $user = User::factory()->withTeam($hotel->team)->create();

        $run = app(ScanService::class)->startManual($hotel, $user);

        $this->assertSame(ScanTrigger::Manual, $run->trigger);
        $this->assertSame(ScanStatus::Queued, $run->status);
        $this->assertSame($user->id, $run->triggered_by);
        Queue::assertPushed(ScanHotelJob::class, fn (ScanHotelJob $job) => $job->scanRunId === $run->id);
    }

    public function test_the_monthly_allowance_blocks_a_second_manual_scan(): void
    {
        $hotel = $this->scannableHotel(plan: Plan::Starter); // 1 manual scan a month
        $user = User::factory()->withTeam($hotel->team)->create();
        $first = app(ScanService::class)->startManual($hotel, $user);
        $first->update(['status' => ScanStatus::Completed, 'finished_at' => now()]);
        $this->travel(2)->days();

        try {
            app(ScanService::class)->startManual($hotel, $user);
            $this->fail('The second manual scan this month must be refused.');
        } catch (ManualScanNotAllowed $e) {
            $this->assertStringStartsWith('Manual scans used (1 of 1).', $e->getMessage());
        }

        $this->assertSame(1, $hotel->scanRuns()->count());
    }

    public function test_the_24_hour_rule_applies_to_members(): void
    {
        $hotel = $this->scannableHotel(plan: Plan::Pro); // 2 a month
        $user = User::factory()->withTeam($hotel->team)->create();
        app(ScanService::class)->startManual($hotel, $user)->update(['status' => ScanStatus::Completed, 'finished_at' => now()]);

        $this->expectException(ManualScanNotAllowed::class);
        $this->expectExceptionMessage('One manual scan per hotel every 24 hours');

        app(ScanService::class)->startManual($hotel, $user);
    }

    public function test_admins_skip_the_allowance_and_the_24_hour_rule_and_do_not_use_the_allowance(): void
    {
        $hotel = $this->scannableHotel(plan: Plan::Starter);
        $member = User::factory()->withTeam($hotel->team)->create();
        $admin = User::factory()->admin()->create();

        foreach (range(1, 3) as $i) {
            $run = app(ScanService::class)->startManual($hotel, $admin);
            $this->assertSame(ScanTrigger::Admin, $run->trigger);
            $run->update(['status' => ScanStatus::Completed, 'finished_at' => now()]);
        }

        // The member's allowance is untouched by the admin scans (the 24-hour rule still applies to them).
        $this->travel(25)->hours();
        $this->assertSame(ScanTrigger::Manual, app(ScanService::class)->startManual($hotel, $member)->trigger);
    }

    public function test_a_running_scan_blocks_everyone_including_admins(): void
    {
        $hotel = $this->scannableHotel();
        $admin = User::factory()->admin()->create();
        app(ScanService::class)->startManual($hotel, $admin); // stays queued (queue is faked)

        $this->expectException(ManualScanNotAllowed::class);
        $this->expectExceptionMessage('A scan is already running for this hotel.');

        app(ScanService::class)->startManual($hotel, $admin);
    }
}
