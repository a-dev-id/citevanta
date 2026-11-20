<?php

namespace Tests\Feature\Recommendations;

use App\Enums\Effort;
use App\Enums\Impact;
use App\Enums\RecommendationCategory;
use App\Enums\RecommendationStatus;
use App\Livewire\Hotels\RecommendationsPage;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class RecommendationsPageTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /** @return array{0: Hotel, 1: User} */
    private function hotelAndMember(): array
    {
        $hotel = $this->scannableHotel(prompts: 0);

        return [$hotel, User::factory()->withTeam($hotel->team)->create(['name' => 'Wayan'])];
    }

    public function test_quick_wins_come_first_and_the_list_shows_why_steps_and_evidence(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        Recommendation::factory()->for($hotel)->quickWin()->create([
            'title' => 'Correct your spa details',
            'steps' => ['Check the spa page.'],
            'evidence' => [['type' => 'issue', 'label' => 'Accuracy issue: Spa', 'url' => '/accuracy#issue-1']],
        ]);
        Recommendation::factory()->for($hotel)->create(['title' => 'Write a families section']);

        $this->actingAs($user)->get(route('hotels.recommendations', $hotel))
            ->assertOk()
            ->assertSeeInOrder(['Quick wins', 'Correct your spa details', 'All recommendations', 'Correct your spa details', 'Write a families section'])
            ->assertSee('Check the spa page.')
            ->assertSee('href="/accuracy#issue-1"', false);
    }

    public function test_another_teams_hotel_is_forbidden(): void
    {
        [$hotel] = $this->hotelAndMember();

        $this->actingAs(User::factory()->withTeam()->create())->get(route('hotels.recommendations', $hotel))->assertForbidden();
    }

    public function test_status_changes_with_one_click_and_shows_who_changed_it(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $recommendation = Recommendation::factory()->for($hotel)->create();

        Livewire::actingAs($user)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->call('setStatus', $recommendation->id, 'in_progress')
            ->assertSee('changed by Wayan');

        $this->assertSame([RecommendationStatus::InProgress, $user->id], [$recommendation->fresh()->status, $recommendation->fresh()->status_changed_by]);
    }

    public function test_dismissing_needs_a_reason(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $recommendation = Recommendation::factory()->for($hotel)->create();

        Livewire::actingAs($user)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->call('startDismiss', $recommendation->id)
            ->call('dismiss')
            ->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'We are adults only')
            ->call('dismiss')
            ->assertHasNoErrors();

        $this->assertSame([RecommendationStatus::Dismissed, 'We are adults only'], [$recommendation->fresh()->status, $recommendation->fresh()->dismissed_reason]);
    }

    public function test_a_recommendation_of_another_hotel_cannot_be_changed(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $foreign = Recommendation::factory()->create();

        Livewire::actingAs($user)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->call('setStatus', $foreign->id, 'done')
            ->assertNotFound();

        $this->assertSame(RecommendationStatus::New, $foreign->fresh()->status);
    }

    public function test_looks_done_can_be_confirmed(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        ScanRun::factory()->for($hotel)->create();
        $recommendation = Recommendation::factory()->for($hotel)->create(['looks_done_at' => now()]);

        Livewire::actingAs($user)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->assertSee('Did you do it?')
            ->call('confirmDone', $recommendation->id);

        $recommendation->refresh();
        $this->assertSame(RecommendationStatus::Done, $recommendation->status);
        $this->assertNull($recommendation->looks_done_at);
        $this->assertNotNull($recommendation->done_baseline_scan_id);
    }

    public function test_filters_and_sorting(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        Recommendation::factory()->for($hotel)->create(['title' => 'Slow task', 'effort' => Effort::Ongoing, 'impact' => Impact::High, 'priority' => 80, 'category' => RecommendationCategory::TravelMedia]);
        Recommendation::factory()->for($hotel)->create(['title' => 'Quick task', 'effort' => Effort::Minutes, 'impact' => Impact::Low, 'priority' => 40, 'category' => RecommendationCategory::Facts]);
        Recommendation::factory()->for($hotel)->status(RecommendationStatus::Done)->create(['title' => 'Finished task']);

        Livewire::actingAs($user)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->assertSeeInOrder(['Slow task', 'Quick task'])
            ->assertDontSee('Finished task')
            ->set('sort', 'effort')
            ->assertSeeInOrder(['Quick task', 'Slow task'])
            ->set('category', RecommendationCategory::Facts->value)
            ->assertDontSee('Slow task')
            ->set('category', '')->set('status', 'done')
            ->assertSee('Finished task')->assertDontSee('Quick task');
    }

    public function test_the_dashboard_shows_the_top_three_open_recommendations(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        ScanRun::factory()->for($hotel)->create();
        foreach ([90, 80, 70, 60] as $priority) {
            Recommendation::factory()->for($hotel)->create(['title' => "Task {$priority}", 'priority' => $priority]);
        }

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('Top recommendations')
            ->assertSee('4 open items')
            ->assertSeeInOrder(['Task 90', 'Task 80', 'Task 70'])
            ->assertDontSee('Task 60');
    }
}
