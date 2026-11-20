<?php

namespace Tests\Feature\Hotels;

use App\Enums\Persona;
use App\Enums\Plan;
use App\Enums\PromptSource;
use App\Livewire\Hotels\PromptsManager;
use App\Models\Hotel;
use App\Models\Prompt;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromptsStepTest extends TestCase
{
    use RefreshDatabase;

    private function setUpHotel(array $attributes = []): array
    {
        $user = User::factory()->withTeam(Team::factory()->plan(Plan::Starter)->create())->create();
        $hotel = Hotel::factory()->for($user->currentTeam)->create($attributes + [
            'name' => 'Alaya Resort Ubud', 'city' => 'Ubud', 'country' => 'Indonesia',
            'hotel_type' => 'resort', 'guest_markets' => ['Australia', 'Germany'], 'setup_step' => 4,
        ]);

        return [$user, $hotel];
    }

    private function manager(User $user, Hotel $hotel)
    {
        return Livewire::actingAs($user)->test(PromptsManager::class, ['hotelId' => $hotel->id, 'wizard' => true]);
    }

    public function test_prompts_are_generated_on_first_entry_from_city_type_and_markets(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $this->manager($user, $hotel)->assertSee('12 of 30 active');

        $prompts = $hotel->prompts()->get();
        $this->assertCount(12, $prompts, '10 persona prompts + 1 per guest market');
        $this->assertTrue($prompts->every(fn (Prompt $p) => $p->source === PromptSource::Generated && $p->is_active && ! $p->is_edited));
        $this->assertTrue($prompts->contains(fn (Prompt $p) => str_contains($p->text, 'from Australia to Ubud')));
        $this->assertTrue($prompts->every(fn (Prompt $p) => str_contains($p->text, 'resort')));

        // Opening the step again does not generate a second set.
        $this->manager($user, $hotel);
        $this->assertSame(12, $hotel->prompts()->count());
    }

    public function test_prompts_containing_the_hotel_name_are_rejected(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $component = $this->manager($user, $hotel);

        foreach (['Is Alaya Resort Ubud good for families?', 'Is the alaya a good place for a honeymoon?'] as $text) {
            $component->set('newText', $text)->call('add')->assertHasErrors('newText');
        }

        $first = $hotel->prompts()->first();
        $component->set("rows.{$first->id}.text", 'Which is better, Alaya or a villa in Ubud?')
            ->assertHasErrors("rows.{$first->id}.text");

        $this->assertNotSame('Which is better, Alaya or a villa in Ubud?', $first->fresh()->text);
        $this->assertSame(12, $hotel->prompts()->count());
    }

    public function test_short_and_duplicate_prompts_are_rejected(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $existing = $hotel->prompts()->create(['text' => 'Best resort in Ubud for a quiet retreat?', 'persona' => Persona::Leisure, 'language' => 'en', 'source' => PromptSource::Custom, 'is_active' => true]);

        $this->manager($user, $hotel)
            ->set('newText', 'Too short')
            ->call('add')
            ->assertHasErrors('newText')
            ->set('newText', 'best resort in Ubud, for a quiet retreat')
            ->call('add')
            ->assertHasErrors('newText');

        $this->assertSame(1, $hotel->prompts()->where('text', $existing->text)->count());
    }

    public function test_custom_prompts_can_be_added(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $this->manager($user, $hotel)
            ->set('newText', 'Which resort near Ubud has the best yoga classes?')
            ->set('newPersona', 'adventure')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('newText', '');

        $prompt = $hotel->prompts()->where('source', PromptSource::Custom)->sole();
        $this->assertSame(Persona::Adventure, $prompt->persona);
        $this->assertTrue($prompt->is_active);
    }

    public function test_the_active_prompt_limit_is_enforced(): void
    {
        config(['citevanta.plans.starter.prompts_per_hotel' => 12]);
        [$user, $hotel] = $this->setUpHotel();

        $component = $this->manager($user, $hotel)->assertSee('12 of 12 active');

        $component->set('newText', 'Which resort near Ubud has the best yoga classes?')
            ->call('add')
            ->assertHasErrors('newText');

        $first = $hotel->prompts()->first();
        $component->set("rows.{$first->id}.is_active", false)
            ->assertHasNoErrors("rows.{$first->id}.is_active")
            ->assertSee('11 of 12 active');

        $component->set('newText', 'Which resort near Ubud has the best yoga classes?')->call('add')->assertHasNoErrors();

        // Re-activating the first one would exceed the limit: refused and the toggle snaps back.
        $component->set("rows.{$first->id}.is_active", true)
            ->assertHasErrors("rows.{$first->id}.is_active")
            ->assertSet("rows.{$first->id}.is_active", false);

        $this->assertSame(12, $hotel->prompts()->where('is_active', true)->count());
    }

    public function test_the_language_limit_is_enforced(): void
    {
        [$user, $hotel] = $this->setUpHotel(); // Starter: 2 languages
        $component = $this->manager($user, $hotel);
        [$first, $second] = $hotel->prompts()->take(2)->get();

        $component->set("rows.{$first->id}.language", 'id')->assertHasNoErrors();
        $component->set("rows.{$second->id}.language", 'de')->assertHasErrors("rows.{$second->id}.language");

        $this->assertSame(['en', 'id'], $hotel->prompts()->distinct()->orderBy('language')->pluck('language')->all());
    }

    public function test_editing_marks_generated_prompts_as_edited(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $component = $this->manager($user, $hotel);
        $prompt = $hotel->prompts()->first();

        $component->set("rows.{$prompt->id}.text", 'Which resort in Ubud has the calmest rooms for light sleepers?')
            ->assertHasNoErrors();

        $this->assertTrue($prompt->fresh()->is_edited);
        $this->assertSame(PromptSource::Generated, $prompt->fresh()->source);
    }

    public function test_regeneration_keeps_edited_and_custom_prompts(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $component = $this->manager($user, $hotel);

        $edited = $hotel->prompts()->first();
        $component->set("rows.{$edited->id}.text", 'Which resort in Ubud has the calmest rooms for light sleepers?');
        $component->set('newText', 'Which resort near Ubud has the best yoga classes?')->call('add');
        $untouched = $hotel->prompts()->where('is_edited', false)->where('source', PromptSource::Generated)->pluck('id');

        $component->call('regenerate')->assertSet('notice', fn ($notice) => str_contains($notice, 'kept'));

        $this->assertTrue(Prompt::find($edited->id)->exists, 'edited prompt kept');
        $this->assertSame(1, $hotel->prompts()->where('source', PromptSource::Custom)->count(), 'custom prompt kept');
        $this->assertSame(0, $hotel->prompts()->whereIn('id', $untouched)->count(), 'unedited suggestions replaced');
        $this->assertSame($untouched->count(), Prompt::onlyTrashed()->whereIn('id', $untouched)->count(), 'replaced prompts are soft deleted');
        // The edited prompt + the custom prompt + a fresh set of 12 suggestions.
        $this->assertSame(14, $hotel->prompts()->count());
    }

    public function test_deleting_a_prompt_soft_deletes_it(): void
    {
        [$user, $hotel] = $this->setUpHotel();
        $component = $this->manager($user, $hotel);
        $prompt = $hotel->prompts()->first();

        $component->call('remove', $prompt->id)->assertSee('11 of 30 active');

        $this->assertSoftDeleted($prompt);
    }

    public function test_finish_hands_over_to_the_wizard(): void
    {
        [$user, $hotel] = $this->setUpHotel();

        $this->manager($user, $hotel)->call('finish')->assertDispatched('wizard-finish');
    }
}
