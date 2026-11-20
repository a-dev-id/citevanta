<?php

namespace Tests\Feature\Insights;

use App\Enums\IssueStatus;
use App\Livewire\Hotels\AccuracyPage;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

class AccuracyPageTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    /** @return array{0: Hotel, 1: User} */
    private function hotelAndMember(): array
    {
        $hotel = $this->scannableHotel(prompts: 0);
        $user = User::factory()->withTeam($hotel->team)->create();

        return [$hotel, $user];
    }

    public function test_members_see_open_issues_with_the_quote_and_a_link_to_the_answer(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $scan = ScanRun::factory()->for($hotel)->create();
        $response = ScanResponse::factory()->for($scan, 'scanRun')->create();
        AccuracyIssue::factory()->for($hotel)->create([
            'fact_key' => 'Kids club', 'quote' => 'Famous for its huge kids club.', 'example_response_ids' => [$response->id], 'last_seen_scan_id' => $scan->id,
        ]);
        AccuracyIssue::factory()->for($hotel)->missingFact()->create(['fact_key' => 'Airport transfer']);

        $this->actingAs($user)->get(route('hotels.accuracy', $hotel))
            ->assertOk()
            ->assertSee('Famous for its huge kids club.')
            ->assertSee(route('hotels.scans.show', [$hotel, $scan]).'#answer-'.$response->id, false)
            ->assertSeeInOrder(['Missing facts', 'Airport transfer']);
    }

    public function test_another_teams_hotel_is_forbidden(): void
    {
        [$hotel] = $this->hotelAndMember();
        $outsider = User::factory()->withTeam()->create();

        $this->actingAs($outsider)->get(route('hotels.accuracy', $hotel))->assertForbidden();
    }

    public function test_dismissing_needs_a_reason_and_records_who_dismissed_it(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $issue = AccuracyIssue::factory()->for($hotel)->create();

        Livewire::actingAs($user)->test(AccuracyPage::class, ['hotel' => $hotel])
            ->call('startDismiss', $issue->id)
            ->set('reason', '')
            ->call('dismiss')
            ->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'We added a kids club last month')
            ->call('dismiss')
            ->assertHasNoErrors();

        $issue->refresh();
        $this->assertSame([IssueStatus::Dismissed, 'We added a kids club last month', $user->id],
            [$issue->status, $issue->dismissed_reason, $issue->dismissed_by]);
    }

    public function test_an_issue_of_another_hotel_cannot_be_dismissed_through_this_page(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        $foreign = AccuracyIssue::factory()->create();

        Livewire::actingAs($user)->test(AccuracyPage::class, ['hotel' => $hotel])
            ->call('startDismiss', $foreign->id)
            ->assertNotFound();

        $this->assertSame(IssueStatus::Open, $foreign->fresh()->status);
    }

    public function test_filters_show_only_matching_issues(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        AccuracyIssue::factory()->for($hotel)->create(['fact_key' => 'Kids club']);
        AccuracyIssue::factory()->for($hotel)->medium()->create(['fact_key' => 'Check-in time', 'claim_key' => 'issue|check-in|1pm']);
        AccuracyIssue::factory()->for($hotel)->dismissed()->create(['fact_key' => 'Gym', 'claim_key' => 'issue|gym|has-gym']);

        Livewire::actingAs($user)->test(AccuracyPage::class, ['hotel' => $hotel])
            ->assertSee('Kids club')->assertSee('Check-in time')->assertDontSee('Gym')
            ->set('severity', 'medium')
            ->assertDontSee('Kids club')->assertSee('Check-in time')
            ->set('severity', '')->set('status', 'dismissed')
            ->assertSee('Gym')->assertDontSee('Kids club');
    }

    public function test_the_dashboard_card_lists_open_issues_most_serious_first(): void
    {
        [$hotel, $user] = $this->hotelAndMember();
        ScanRun::factory()->for($hotel)->create();
        AccuracyIssue::factory()->for($hotel)->medium()->create(['fact_key' => 'Check-in time', 'claim_key' => 'issue|check-in|1pm', 'occurrences' => 9]);
        AccuracyIssue::factory()->for($hotel)->create(['fact_key' => 'Kids club']);
        AccuracyIssue::factory()->for($hotel)->dismissed()->create(['fact_key' => 'Gym', 'claim_key' => 'issue|gym|has-gym']);

        $this->actingAs($user)->get(route('hotels.show', $hotel))
            ->assertOk()
            ->assertSee('What AI gets wrong')
            ->assertSeeInOrder(['Kids club', 'Check-in time'])
            ->assertDontSee('Gym');
    }
}
