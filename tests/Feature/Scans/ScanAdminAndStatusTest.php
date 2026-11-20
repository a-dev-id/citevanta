<?php

namespace Tests\Feature\Scans;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Livewire\Hotels\ScanStatus as ScanStatusComponent;
use App\Models\User;
use App\Services\Cron\CronRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class ScanAdminAndStatusTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    public function test_the_system_page_shows_health_and_both_cron_lines_to_admins(): void
    {
        config(['citevanta.cron.token' => 'secret-token-for-the-admin-page-0123456789']);
        Cache::forever(CronRunner::LAST_RUN_KEY, ['mode' => 'cli unlimited', 'scheduler' => 'in-process', 'time_limit' => 0, 'ran_at' => now()->subMinutes(20)->toIso8601String()]);

        $this->actingAs(User::factory()->admin()->withTeam()->create())->get('/admin/system')
            ->assertOk()
            ->assertSee('Cron may not be running')
            ->assertSee('cli unlimited')
            ->assertSeeInOrder(['artisan&quot; citevanta:cron', '/cron/secret-token-for-the-admin-page-0123456789'], false)
            ->assertSee('citevanta:dispatch-due-scans');
    }

    public function test_admins_can_retry_and_delete_failed_jobs(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'abc-123', 'connection' => 'database', 'queue' => 'scans',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ScanPromptJob', 'uuid' => 'abc-123', 'job' => 'x', 'data' => []]),
            'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
        ]);
        $admin = User::factory()->admin()->withTeam()->create();

        $this->actingAs($admin)->get('/admin/failed-jobs')->assertOk()->assertSee('ScanPromptJob')->assertSee('RuntimeException: boom');

        $this->actingAs($admin)->delete('/admin/failed-jobs/abc-123')->assertRedirect();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_admin_scan_runs_page_offers_retry_for_missing_calls(): void
    {
        $hotel = $this->scannableHotel(prompts: 1);
        $run = $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Manual, 'status' => ScanStatus::CompletedWithErrors,
            'prompt_ids' => [$hotel->prompts()->first()->id], 'runs_per_prompt' => 2, 'failed_count' => 2,
            'errors' => ['Prompt #1 run 1 (openai): RuntimeException - boom'], 'started_at' => now()->subMinutes(3), 'finished_at' => now(),
        ]);
        $admin = User::factory()->admin()->withTeam()->create();

        $this->actingAs($admin)->get('/admin/scan-runs')
            ->assertOk()
            ->assertSee('Retry failed calls (2)')
            ->assertSee('RuntimeException - boom');

        Bus::fake();
        $this->actingAs($admin)->post("/admin/scan-runs/{$run->id}/retry")
            ->assertRedirect()
            ->assertSessionHas('status', fn ($message) => str_contains($message, '2 missing call(s) queued'));
        Bus::assertBatchCount(1);
    }

    public function test_the_hotel_page_shows_progress_and_polls_while_a_scan_runs(): void
    {
        $hotel = $this->scannableHotel(prompts: 3);
        $user = User::factory()->withTeam($hotel->team)->create();
        $run = $hotel->scanRuns()->create([
            'engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Setup, 'status' => ScanStatus::Running,
            'started_at' => now()->subMinutes(2), 'prompt_ids' => $hotel->prompts()->pluck('id')->all(), 'runs_per_prompt' => 2,
        ]);
        foreach ($hotel->prompts as $prompt) {
            $run->responses()->create(['prompt_id' => $prompt->id, 'engine' => 'openai', 'run_number' => 1, 'answer_text' => 'x']);
        }

        Livewire::actingAs($user)->test(ScanStatusComponent::class, ['hotelId' => $hotel->id])
            ->assertSee('3 of 6 answers collected')
            ->assertSee('about 2 minutes left') // 1.5 answers a minute, 3 to go
            ->assertSeeHtml('wire:poll.10s');
    }

    public function test_the_hotel_page_shows_the_latest_visibility_and_stops_polling_when_done(): void
    {
        $hotel = $this->scannableHotel(prompts: 2);
        $user = User::factory()->withTeam($hotel->team)->create();
        $run = $hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'm', 'trigger' => ScanTrigger::Setup, 'status' => ScanStatus::Completed, 'started_at' => now(), 'finished_at' => now()]);
        $run->visibilityScores()->create(['hotel_id' => $hotel->id, 'engine' => 'openai', 'persona' => null, 'mentions' => 3, 'answers' => 8, 'visibility_pct' => 37.5, 'positive' => 2, 'neutral' => 1, 'negative' => 0]);

        // Banner: nothing to show and no polling once the scan is done.
        Livewire::actingAs($user)->test(ScanStatusComponent::class, ['hotelId' => $hotel->id])
            ->assertDontSee('Scan running')
            ->assertDontSeeHtml('wire:poll');

        // A banner that saw a running scan finish tells the dashboard to refresh.
        Livewire::actingAs($user)->test(ScanStatusComponent::class, ['hotelId' => $hotel->id])
            ->set('wasActive', true)
            ->assertDispatched('scan-finished')
            ->assertSet('wasActive', false);

        // The dashboard shows the stored visibility.
        $this->actingAs($user)->get(route('hotels.show', $hotel))->assertOk()->assertSee('37.5%');
    }

    public function test_another_teams_scan_status_is_off_limits(): void
    {
        $hotel = $this->scannableHotel();

        Livewire::actingAs(User::factory()->withTeam()->create())
            ->test(ScanStatusComponent::class, ['hotelId' => $hotel->id])
            ->assertForbidden();
    }
}
