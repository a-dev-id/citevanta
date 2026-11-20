<?php

namespace Tests\Feature\Hotels;

use App\Livewire\Hotels\FactsForm;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HotelFactsStepTest extends TestCase
{
    use RefreshDatabase;

    private function setUpHotel(): array
    {
        $user = User::factory()->withTeam()->create();

        return [$user, Hotel::factory()->for($user->currentTeam)->create(['setup_step' => 2])];
    }

    public function test_suggested_keys_are_prefilled_with_empty_values(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $facts = Livewire::actingAs($user)->test(FactsForm::class, ['hotelId' => $hotel->id, 'wizard' => true])->get('facts');

        $this->assertSame(config('citevanta.suggested_facts'), array_column($facts, 'key'));
        $this->assertSame([''], array_values(array_unique(array_column($facts, 'value'))));
    }

    public function test_filled_rows_are_saved_and_empty_rows_skipped(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        Livewire::actingAs($user)->test(FactsForm::class, ['hotelId' => $hotel->id, 'wizard' => true])
            ->set('facts', [
                ['key' => 'Swimming pool', 'value' => 'Infinity pool facing the jungle'],
                ['key' => 'Kids club', 'value' => ''],
                ['key' => 'Check-in time', 'value' => '14:00'],
            ])
            ->call('addRow')
            ->set('facts.3', ['key' => 'Yoga', 'value' => 'Daily at 7am'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('wizard-step-saved', step: 2);

        $this->assertSame(
            ['Swimming pool' => 'Infinity pool facing the jungle', 'Check-in time' => '14:00', 'Yoga' => 'Daily at 7am'],
            $hotel->facts()->pluck('value', 'key')->all(),
        );
        $this->assertSame(3, $hotel->fresh()->setup_step);
    }

    public function test_duplicate_keys_and_values_without_a_key_are_rejected(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        Livewire::actingAs($user)->test(FactsForm::class, ['hotelId' => $hotel->id, 'wizard' => true])
            ->set('facts', [
                ['key' => 'Spa', 'value' => 'Yes'],
                ['key' => 'spa', 'value' => 'Two treatment rooms'],
                ['key' => '', 'value' => 'Orphan value'],
            ])
            ->call('save')
            ->assertHasErrors(['facts.1.key', 'facts.2.key'])
            ->assertNotDispatched('wizard-step-saved');

        $this->assertSame(0, $hotel->facts()->count());
    }

    public function test_removing_a_row_and_saving_deletes_the_fact(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $hotel->facts()->createMany([['key' => 'Spa', 'value' => 'Yes'], ['key' => 'Parking', 'value' => 'Free']]);

        Livewire::actingAs($user)->test(FactsForm::class, ['hotelId' => $hotel->id])
            ->call('removeRow', 0)
            ->call('save')
            ->assertSet('saved', true);

        $this->assertSame(['Parking'], $hotel->facts()->pluck('key')->all());
    }

    public function test_another_teams_facts_are_off_limits(): void
    {
        $other = Hotel::factory()->for(Team::factory())->create();

        Livewire::actingAs(User::factory()->withTeam()->create())
            ->test(FactsForm::class, ['hotelId' => $other->id])
            ->assertForbidden();
    }
}
