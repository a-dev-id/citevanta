<?php

namespace Tests\Feature\Hotels;

use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Livewire\Hotels\SetupWizard;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Services\Scanning\ManualScanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function user(Plan $plan = Plan::Starter): User
    {
        return User::factory()->withTeam(Team::factory()->plan($plan)->create())->create();
    }

    public function test_the_wizard_pages_render(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 2]);

        $this->actingAs($user)->get('/hotels/create')->assertOk()->assertSee('Hotel details')->assertSee('cv-stepper', false);
        $this->actingAs($user)->get("/hotels/{$hotel->id}/setup")->assertOk()->assertSee('Hotel facts');
    }

    public function test_creating_the_hotel_continues_at_its_setup_url(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create();

        Livewire::actingAs($user)->test(SetupWizard::class)
            ->dispatch('wizard-step-saved', hotelId: $hotel->id, step: 1)
            ->assertRedirect(route('hotels.setup', $hotel));
    }

    public function test_leaving_after_step_b_and_coming_back_opens_step_c(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 2]);

        Livewire::actingAs($user)->test(SetupWizard::class, ['hotel' => $hotel])
            ->assertSet('step', 2)
            ->set('step', 2)
            ->dispatch('wizard-step-saved', hotelId: $hotel->id, step: 2)
            ->assertSet('step', 3);

        // Facts step completion is saved by the FactsForm; simulate leaving and coming back.
        $hotel->update(['setup_step' => 3]);

        Livewire::actingAs($user)->test(SetupWizard::class, ['hotel' => $hotel->fresh()])
            ->assertSet('step', 3)
            ->assertSee('Competitors');
    }

    public function test_back_and_jumping_only_to_reached_steps(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 3]);

        Livewire::actingAs($user)->test(SetupWizard::class, ['hotel' => $hotel])
            ->dispatch('wizard-back')->assertSet('step', 2)
            ->call('goTo', 1)->assertSet('step', 1)
            ->call('goTo', 4)->assertSet('step', 1, 'step 4 is not reached yet');
    }

    public function test_a_user_cannot_open_another_teams_wizard(): void
    {
        $other = Hotel::factory()->for(Team::factory())->create(['setup_step' => 2]);

        $this->actingAs($this->user())->get("/hotels/{$other->id}/setup")->assertForbidden();
        Livewire::actingAs($this->user())->test(SetupWizard::class, ['hotel' => $other])->assertForbidden();
    }

    public function test_finish_marks_setup_done_and_queues_the_first_scan_without_using_the_allowance(): void
    {
        Queue::fake();
        $user = $this->user(Plan::Starter);
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 4, 'name' => 'Alaya Resort Ubud', 'city' => 'Ubud']);
        $hotel->prompts()->create(['text' => 'Best resort in Ubud for a quiet retreat?', 'persona' => 'leisure', 'language' => 'en', 'is_active' => true]);

        Livewire::actingAs($user)->test(SetupWizard::class, ['hotel' => $hotel])
            ->dispatch('wizard-finish')
            ->assertRedirect(route('hotels.show', $hotel));

        $hotel->refresh();
        $this->assertNotNull($hotel->setup_completed_at);

        $scan = $hotel->scanRuns()->sole();
        $this->assertSame(ScanTrigger::Setup, $scan->trigger);
        $this->assertSame(ScanStatus::Queued, $scan->status);
        $this->assertSame($user->id, $scan->triggered_by);
        Queue::assertPushed(ScanHotelJob::class, fn (ScanHotelJob $job) => $job->scanRunId === $scan->id);

        $this->assertSame(0, app(ManualScanGate::class)->usedThisMonth($hotel), 'the first scan is not a manual scan');

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('Setup complete. Your first scan has started.');
    }

    public function test_finish_needs_at_least_one_active_prompt(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 4, 'country' => 'Indonesia']);

        $wizard = Livewire::actingAs($user)->test(SetupWizard::class, ['hotel' => $hotel]);
        // Opening step 4 generates suggestions; the user switches them all off.
        $hotel->prompts()->update(['is_active' => false]);

        $wizard->dispatch('wizard-finish')
            ->assertHasErrors('finish')
            ->assertNoRedirect();

        $this->assertNull($hotel->fresh()->setup_completed_at);
        $this->assertSame(0, $hotel->scanRuns()->count());
    }

    public function test_unfinished_hotels_link_to_finish_setup(): void
    {
        $user = $this->user(Plan::Pro);
        $user->currentTeam->update(['plan' => Plan::Pro]);
        config(['citevanta.plans.pro.hotels' => 2]);
        $draft = Hotel::factory()->for($user->currentTeam)->create(['name' => 'Draft Villa', 'setup_step' => 2]);
        Hotel::factory()->for($user->currentTeam)->create(['name' => 'Ready Resort', 'setup_completed_at' => now()]);

        $this->actingAs($user)->get('/hotels')
            ->assertOk()
            ->assertSee('Finish setup')
            ->assertSee(route('hotels.setup', $draft), false);

        // The header hotel switcher (shown with 2+ hotels) also marks it.
        $this->actingAs($user)->get('/dashboard')->assertSee('Switch hotel')->assertSee('Finish setup');
    }

    public function test_edit_prompts_and_competitors_pages_reuse_the_step_components(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['setup_completed_at' => now(), 'country' => 'Indonesia', 'hotel_type' => 'resort']);

        $this->actingAs($user)->get("/hotels/{$hotel->id}/edit")->assertOk()->assertSeeLivewire('hotels.details-form')->assertSeeLivewire('hotels.facts-form');
        $this->actingAs($user)->get("/hotels/{$hotel->id}/prompts")->assertOk()->assertSeeLivewire('hotels.prompts-manager');
        $this->actingAs($user)->get("/hotels/{$hotel->id}/competitors")->assertOk()->assertSeeLivewire('hotels.competitors-manager');

        $this->actingAs($user)->get('/prompts')->assertRedirect("/hotels/{$hotel->id}/prompts");
        $this->actingAs($user)->get('/competitors')->assertRedirect("/hotels/{$hotel->id}/competitors");
    }

    public function test_menu_shortcuts_send_users_without_hotels_to_the_wizard(): void
    {
        $this->actingAs($this->user())->get('/prompts')->assertRedirect('/hotels/create');
    }
}
