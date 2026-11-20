<?php

namespace Tests\Feature\Hotels;

use App\Enums\Plan;
use App\Livewire\Hotels\CompetitorsManager;
use App\Models\Competitor;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompetitorsStepTest extends TestCase
{
    use RefreshDatabase;

    private function setUpHotel(): array
    {
        $user = User::factory()->withTeam(Team::factory()->plan(Plan::Starter)->create())->create();
        $hotel = Hotel::factory()->for($user->currentTeam)->create([
            'name' => 'Alaya Resort Ubud', 'city' => 'Ubud', 'setup_step' => 3,
        ]);

        return [$user, $hotel];
    }

    private function manager(User $user, Hotel $hotel)
    {
        return Livewire::actingAs($user)->test(CompetitorsManager::class, ['hotelId' => $hotel->id, 'wizard' => true]);
    }

    public function test_competitors_can_be_added_up_to_the_plan_limit(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $component = $this->manager($user, $hotel)->assertSee('0 of 3 used');

        foreach (['Kayon Jungle Resort', 'Maya Ubud Resort', 'Komaneka at Bisma'] as $name) {
            $component->set('newName', $name)->set('newWebsite', '')->call('add')->assertHasNoErrors();
        }

        $component->assertSee('3 of 3 used')
            ->set('newName', 'Four Seasons Sayan')
            ->call('add')
            ->assertHasErrors('newName');

        $this->assertSame(3, $hotel->competitors()->count());
    }

    public function test_duplicates_are_rejected_using_fuzzy_matching(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        Competitor::factory()->for($hotel)->create(['name' => 'Kayon Jungle Resort']);

        $this->manager($user, $hotel)
            ->set('newName', 'The KAYON Jungle Resort & Spa')
            ->call('add')
            ->assertHasErrors('newName');

        $this->assertSame(1, $hotel->competitors()->count());
    }

    public function test_the_hotel_itself_cannot_be_a_competitor(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $this->manager($user, $hotel)
            ->set('newName', 'ALAYA Ubud')
            ->call('add')
            ->assertHasErrors('newName');

        $this->assertSame(0, $hotel->competitors()->count());
    }

    public function test_rows_save_on_change_and_are_validated(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $first = Competitor::factory()->for($hotel)->create(['name' => 'Kayon Jungle Resort', 'website' => null]);
        Competitor::factory()->for($hotel)->create(['name' => 'Maya Ubud Resort']);

        $this->manager($user, $hotel)
            ->set("rows.{$first->id}.website", 'kayonresort.com')
            ->assertHasNoErrors()
            ->set("rows.{$first->id}.name", 'Maya Ubud')
            ->assertHasErrors("rows.{$first->id}.name");

        $this->assertSame('https://kayonresort.com', $first->fresh()->website);
        $this->assertSame('Kayon Jungle Resort', $first->fresh()->name);
    }

    public function test_removed_competitors_are_soft_deleted_and_restored_when_added_again(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $competitor = Competitor::factory()->for($hotel)->create(['name' => 'Kayon Jungle Resort']);

        $this->manager($user, $hotel)
            ->call('remove', $competitor->id)
            ->assertSee('0 of 3 used')
            ->set('newName', 'Kayon Jungle Resort')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame($competitor->id, $hotel->competitors()->sole()->id, 'the same row (and its history) is restored');
    }

    public function test_continue_moves_to_the_prompts_step(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $this->manager($user, $hotel)->call('continue')->assertDispatched('wizard-step-saved', step: 3);

        $this->assertSame(4, $hotel->fresh()->setup_step);
    }
}
