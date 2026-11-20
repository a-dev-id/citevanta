<?php

namespace Tests\Feature\Hotels;

use App\Enums\Plan;
use App\Livewire\Hotels\DetailsForm;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HotelDetailsStepTest extends TestCase
{
    use RefreshDatabase;

    private function user(Plan $plan = Plan::Starter): User
    {
        return User::factory()->withTeam(Team::factory()->plan($plan)->create())->create();
    }

    private function validInput(): array
    {
        return [
            'name' => 'Alaya Resort Ubud',
            'city' => 'Ubud',
            'country' => 'Indonesia',
            'website' => 'alayahotels.com',
            'hotel_type' => 'resort',
            'star_rating' => '5',
            'guest_markets' => ['Australia', 'Germany'],
        ];
    }

    public function test_valid_details_create_the_hotel_and_move_to_step_two(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test(DetailsForm::class, ['wizard' => true])
            ->set($this->validInput())
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('wizard-step-saved', step: 1);

        $hotel = Hotel::sole();
        $this->assertSame($user->currentTeam->id, $hotel->team_id);
        $this->assertSame('https://alayahotels.com', $hotel->website, 'a missing scheme is added');
        $this->assertSame(5, $hotel->star_rating);
        $this->assertSame(['Australia', 'Germany'], $hotel->guest_markets);
        $this->assertSame(2, $hotel->setup_step);
        $this->assertNull($hotel->setup_completed_at);
    }

    public function test_details_are_validated(): void
    {
        Livewire::actingAs($this->user())->test(DetailsForm::class, ['wizard' => true])
            ->set($this->validInput())
            ->set('name', '')
            ->set('country', 'Atlantis')
            ->set('website', 'not a url')
            ->set('hotel_type', 'castle')
            ->set('star_rating', '7')
            ->set('guest_markets', ['Australia', 'Germany', 'Japan', 'France', 'China', 'India'])
            ->call('save')
            ->assertHasErrors(['name', 'country', 'website', 'hotel_type', 'star_rating', 'guest_markets'])
            ->assertNotDispatched('wizard-step-saved');

        $this->assertSame(0, Hotel::count());
    }

    public function test_hotel_limit_shows_an_alert_and_creates_nothing(): void
    {
        $user = $this->user(Plan::Starter);
        Hotel::factory()->for($user->currentTeam)->create();

        Livewire::actingAs($user)->test(DetailsForm::class, ['wizard' => true])
            ->assertSee('Your Starter plan includes 1 hotel')
            ->set($this->validInput())
            ->call('save')
            ->assertSet('limitMessage', fn ($message) => str_contains($message, 'includes 1 hotel'))
            ->assertNotDispatched('wizard-step-saved');

        $this->assertSame(1, Hotel::count());
    }

    public function test_the_same_form_edits_an_existing_hotel(): void
    {
        $user = $this->user();
        $hotel = Hotel::factory()->for($user->currentTeam)->create(['country' => 'Indonesia', 'hotel_type' => 'resort']);

        Livewire::actingAs($user)->test(DetailsForm::class, ['hotelId' => $hotel->id])
            ->assertSet('name', $hotel->name)
            ->set('city', 'Canggu')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $this->assertSame('Canggu', $hotel->fresh()->city);
    }

    public function test_a_user_cannot_edit_another_teams_hotel(): void
    {
        $other = Hotel::factory()->for(Team::factory())->create();

        Livewire::actingAs($this->user())->test(DetailsForm::class, ['hotelId' => $other->id])->assertForbidden();
    }
}
