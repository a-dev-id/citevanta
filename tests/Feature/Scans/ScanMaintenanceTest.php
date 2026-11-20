<?php

namespace Tests\Feature\Scans;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\Team;
use App\Services\Scanning\ScanMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class ScanMaintenanceTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    private function completedScan(Hotel $hotel, int $daysAgo): ScanRun
    {
        return $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Scheduled, 'status' => ScanStatus::Completed,
            'started_at' => now()->subDays($daysAgo), 'finished_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_due_scans_are_queued_for_hotels_whose_last_scan_is_old_enough(): void
    {
        Queue::fake();
        // Starter: 2 scans a month = every 15 days.
        $due = $this->scannableHotel(attributes: ['name' => 'Due Resort']);
        $this->completedScan($due, 16);
        $recent = $this->scannableHotel(attributes: ['name' => 'Recent Resort']);
        $this->completedScan($recent, 10);
        $never = $this->scannableHotel(attributes: ['name' => 'Never Scanned Resort']);

        $this->assertSame(2, app(ScanMaintenance::class)->dispatchDue());

        $this->assertSame(ScanTrigger::Scheduled, $due->scanRuns()->latest('id')->first()->trigger);
        $this->assertSame(ScanStatus::Queued, $due->scanRuns()->latest('id')->first()->status);
        $this->assertSame(1, $never->scanRuns()->count());
        $this->assertSame(1, $recent->scanRuns()->count(), 'not due yet');
        Queue::assertPushed(ScanHotelJob::class, 2);
    }

    public function test_due_scans_skip_expired_trials_unfinished_setups_and_hotels_already_scanning(): void
    {
        Queue::fake();
        $expired = $this->scannableHotel(attributes: ['name' => 'Expired Trial Resort']);
        $expired->team->update(['plan' => Plan::Trial, 'trial_ends_at' => now()->subDay()]);

        $notSetUp = $this->scannableHotel(attributes: ['name' => 'Draft Resort', 'setup_completed_at' => null]);

        $busy = $this->scannableHotel(attributes: ['name' => 'Busy Resort']);
        $busy->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Running, 'started_at' => now()]);

        $this->assertSame(0, app(ScanMaintenance::class)->dispatchDue());
        Queue::assertNothingPushed();
    }

    public function test_queued_scans_without_a_batch_are_dispatched_after_five_minutes(): void
    {
        Queue::fake();
        $hotel = $this->scannableHotel();
        $old = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Setup, 'status' => ScanStatus::Queued]);
        $old->forceFill(['created_at' => now()->subMinutes(6)])->save();

        $other = $this->scannableHotel(attributes: ['name' => 'Fresh Resort']);
        $other->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Setup, 'status' => ScanStatus::Queued]);

        $this->artisan('citevanta:dispatch-queued-scans')->expectsOutputToContain('1 queued scan(s) dispatched.');

        Queue::assertPushed(ScanHotelJob::class, fn (ScanHotelJob $job) => $job->scanRunId === $old->id);
        Queue::assertPushed(ScanHotelJob::class, 1);
    }

    public function test_stuck_scans_are_closed_with_what_they_collected(): void
    {
        $withAnswers = $this->scannableHotel(prompts: 2);
        $stuck = $withAnswers->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Running,
            'started_at' => now()->subHours(3), 'prompt_ids' => $withAnswers->prompts()->pluck('id')->all(), 'runs_per_prompt' => 2,
        ]);
        $response = $stuck->responses()->create(['prompt_id' => $withAnswers->prompts()->first()->id, 'engine' => 'openai', 'model' => 'm', 'run_number' => 1, 'answer_text' => '1. Maya Ubud - ok']);
        $response->analysis()->create(['hotel_mentioned' => false, 'sentiment' => 'not_mentioned', 'competitors_mentioned' => [], 'other_hotels_mentioned' => [], 'summary' => 's']);
        $response->forceFill(['created_at' => now()->subMinutes(100)])->save();

        $empty = $this->scannableHotel(attributes: ['name' => 'Empty Resort']);
        $nothing = $empty->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Running, 'started_at' => now()->subHours(2)]);

        $active = $this->scannableHotel(attributes: ['name' => 'Active Resort']);
        $busy = $active->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::Running, 'started_at' => now()->subHours(3)]);
        $busy->responses()->create(['prompt_id' => $active->prompts()->first()->id, 'engine' => 'openai', 'run_number' => 1, 'answer_text' => 'recent']);

        $this->artisan('citevanta:fail-stuck-scans')->expectsOutputToContain('2 stuck scan(s) closed.');

        $this->assertSame(ScanStatus::CompletedWithErrors, $stuck->fresh()->status);
        $this->assertSame(1, $stuck->fresh()->answers_count);
        $this->assertSame(3, $stuck->fresh()->failed_count, '4 planned, 1 answered');
        $this->assertSame(ScanStatus::Failed, $nothing->fresh()->status);
        $this->assertSame(ScanStatus::Running, $busy->fresh()->status, 'an answer 0 minutes ago is progress');
    }

    public function test_housekeeping_prunes_old_batches_and_failed_jobs(): void
    {
        \DB::table('failed_jobs')->insert([
            ['uuid' => 'old', 'connection' => 'database', 'queue' => 'scans', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(31)],
            ['uuid' => 'new', 'connection' => 'database', 'queue' => 'scans', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(2)],
        ]);

        $this->artisan('citevanta:housekeeping')->assertSuccessful();

        $this->assertSame(['new'], \DB::table('failed_jobs')->pluck('uuid')->all());
    }

    public function test_expired_trial_teams_factory_state_is_respected(): void
    {
        $this->assertTrue(Team::factory()->expiredTrial()->create()->trialHasExpired());
    }
}
