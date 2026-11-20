<?php

namespace Tests\Feature\Prompts;

use App\Enums\Plan;
use App\Livewire\Hotels\DetailsForm;
use App\Livewire\Hotels\PromptsManager;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Services\Hotels\PromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MultiLanguagePromptsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Hotel} */
    private function setUpHotel(Plan $plan, array $markets): array
    {
        $user = User::factory()->withTeam(Team::factory()->plan($plan)->create())->create();
        $hotel = Hotel::factory()->for($user->currentTeam)->create([
            'name' => 'Alaya Resort Ubud', 'city' => 'Ubud', 'country' => 'Indonesia',
            'hotel_type' => 'resort', 'guest_markets' => $markets, 'setup_step' => 4,
        ]);

        return [$user, $hotel];
    }

    public function test_guest_markets_suggest_languages_english_first(): void
    {
        [, $hotel] = $this->setUpHotel(Plan::Pro, ['China', 'South Korea', 'Japan', 'Indonesia', 'Australia']);

        $this->assertSame(
            ['en' => 'English', 'zh' => 'Chinese', 'ko' => 'Korean', 'ja' => 'Japanese', 'id' => 'Indonesian'],
            app(PromptService::class)->suggestedLanguages($hotel),
        );
    }

    public function test_generation_uses_the_suggested_languages_up_to_the_plans_limit(): void
    {
        [, $hotel] = $this->setUpHotel(Plan::Starter, ['China', 'Japan']);

        app(PromptService::class)->generate($hotel);

        $counts = $hotel->prompts()->get()->countBy('language')->all();
        $this->assertSame(['en' => 12, 'zh' => 6], $counts, 'Starter allows 2 languages: English 10 + 1 per guest market, Chinese 6');
        $this->assertTrue($hotel->prompts()->where('language', 'zh')->get()->every(fn ($p) => str_contains($p->text, '度假村')));
    }

    public function test_prompts_in_chinese_korean_or_japanese_are_not_treated_as_duplicates_of_each_other(): void
    {
        [, $hotel] = $this->setUpHotel(Plan::Pro, []);

        app(PromptService::class)->generate($hotel, 'ja');

        $this->assertSame(10, $hotel->prompts()->where('language', 'ja')->count());
    }

    public function test_a_language_beyond_the_plans_limit_is_refused(): void
    {
        [, $hotel] = $this->setUpHotel(Plan::Starter, ['China']);
        app(PromptService::class)->generate($hotel);

        $this->expectException(ValidationException::class);
        app(PromptService::class)->generate($hotel, 'ko');
    }

    public function test_the_prompts_step_shows_suggested_languages_and_adds_prompts_in_one(): void
    {
        [$user, $hotel] = $this->setUpHotel(Plan::Pro, ['South Korea']);

        Livewire::actingAs($user)->test(PromptsManager::class, ['hotelId' => $hotel->id, 'wizard' => true])
            ->assertSee('Suggested from your guest markets: English, Korean')
            ->set('generateLanguage', 'id')
            ->call('addLanguage')
            ->assertSee('11 prompts added in Indonesian.');

        $this->assertSame(11, $hotel->prompts()->where('language', 'id')->count(), '10 + 1 for the guest market');
    }

    public function test_alternative_names_are_saved_one_per_line(): void
    {
        [$user, $hotel] = $this->setUpHotel(Plan::Pro, []);

        Livewire::actingAs($user)->test(DetailsForm::class, ['hotelId' => $hotel->id])
            ->set('alternative_names', "阿拉亚度假村\n알라야 리조트\n\n阿拉亚度假村")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['阿拉亚度假村', '알라야 리조트'], $hotel->fresh()->alternative_names);
        $this->assertSame(['Alaya Resort Ubud', '阿拉亚度假村', '알라야 리조트'], $hotel->fresh()->allNames());
    }
}
