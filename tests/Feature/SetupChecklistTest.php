<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Enums\RecommendationStatus;
use App\Enums\TeamRole;
use App\Livewire\SetupChecklist as SetupChecklistComponent;
use App\Models\Competitor;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\HotelTracking;
use App\Models\Prompt;
use App\Models\Recommendation;
use App\Models\ScanRun;
use App\Models\SetupChecklistState;
use App\Models\Team;
use App\Models\User;
use App\Services\Setup\SetupChecklist;
use App\Services\Tours\TourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The first-time setup checklist: each item's done rule, the Private-only items, the trial item that is not
 * counted, the saved state per user and team, hide and restore, the "All set" flow, the exclusions (admin pages,
 * unverified emails, tours), the env switch and the admin reset.
 */
class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function checklist(): SetupChecklist
    {
        return app(SetupChecklist::class);
    }

    /** @return array{0: User, 1: Team, 2: Hotel} */
    private function member(Plan $plan = Plan::Starter, bool $verified = true): array
    {
        $team = Team::factory()->create(['plan' => $plan]);
        $user = User::factory()->when(! $verified, fn ($factory) => $factory->unverified())->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['name' => 'Nandini', 'city' => 'Ubud', 'website' => 'https://nandini.example', 'setup_completed_at' => now()]);

        return [$user->fresh(), $team, $hotel];
    }

    private function done(string $key, User $user, Team $team, ?Hotel $hotel): bool
    {
        return $this->checklist()->isDone($key, $user, $team->fresh(), $hotel?->fresh());
    }

    private function facts(Hotel $hotel, array $facts): void
    {
        foreach ($facts as $key => $value) {
            $hotel->facts()->create(['key' => $key, 'value' => $value, 'position' => $hotel->facts()->count()]);
        }
    }

    /** Every item of a Starter team done. */
    private function completeBase(User $user, Team $team, Hotel $hotel): void
    {
        $this->facts($hotel, ['Price range per night' => '$120-200', 'Number of rooms' => '24']);
        Competitor::factory()->count(3)->create(['hotel_id' => $hotel->id]);
        Prompt::factory()->count(10)->create(['hotel_id' => $hotel->id]);
        ScanRun::factory()->create(['hotel_id' => $hotel->id]);
        Recommendation::factory()->status(RecommendationStatus::ToDo)->create(['hotel_id' => $hotel->id]);
    }

    /** @return array<string, bool> */
    private function states(User $user, Team $team): array
    {
        return collect($this->checklist()->items($user, $team->fresh()))->pluck('done', 'key')->all();
    }

    // --- the items and the progress -------------------------------------------------------------

    public function test_every_team_gets_seven_items_and_the_private_plan_four_more(): void
    {
        [$starter, $starterTeam] = $this->member(Plan::Starter);
        [$private, $privateTeam] = $this->member(Plan::Private);

        $this->assertSame(['email', 'hotel', 'facts', 'competitors', 'prompts', 'scan', 'recommendation'], array_column($this->checklist()->items($starter, $starterTeam), 'key'));
        $this->assertSame(['email', 'hotel', 'facts', 'competitors', 'prompts', 'scan', 'recommendation', 'openai_key', 'google', 'script', 'health'], array_column($this->checklist()->items($private, $privateTeam), 'key'));
    }

    public function test_a_trial_team_gets_an_upgrade_item_at_the_end_that_is_not_counted(): void
    {
        [$owner, $team, $hotel] = $this->member(Plan::Trial);
        $items = $this->checklist()->items($owner, $team);

        $last = end($items);
        $this->assertSame(['upgrade', false, route('settings.billing')], [$last['key'], $last['counted'], $last['url']]);
        $this->assertCount(8, $items);
        $this->assertSame(7, $this->checklist()->progress($items)['total'], 'the upgrade item is not part of the progress');

        $this->completeBase($owner, $team, $hotel);
        $progress = $this->checklist()->progress($this->checklist()->items($owner, $team->fresh()));
        $this->assertSame([7, 7, 100, true], [$progress['done'], $progress['total'], $progress['percent'], $progress['complete']], 'all set without upgrading');

        $member = User::factory()->create();
        $team->addMember($member, TeamRole::Member);
        $this->assertNull(collect($this->checklist()->items($member, $team))->firstWhere('key', 'upgrade')['url'], 'only the owner can open Billing');

        [$starter, $starterTeam] = $this->member(Plan::Starter);
        $this->assertNotContains('upgrade', array_column($this->checklist()->items($starter, $starterTeam), 'key'));
    }

    public function test_the_progress_counts_done_items(): void
    {
        [$user, $team, $hotel] = $this->member();

        $progress = $this->checklist()->progress($this->checklist()->items($user, $team));
        $this->assertSame([2, 7, 29, false], [$progress['done'], $progress['total'], $progress['percent'], $progress['complete']], 'the email and the hotel are done');

        $this->facts($hotel, ['Price range per night' => '$100', 'Number of rooms' => '10']);
        $this->assertSame(3, $this->checklist()->progress($this->checklist()->items($user, $team->fresh()))['done']);
    }

    // --- the done rules --------------------------------------------------------------------------

    public function test_verify_your_email(): void
    {
        [$verified, $team] = $this->member();
        [$unverified, $other] = $this->member(verified: false);

        $this->assertTrue($this->done('email', $verified, $team, null));
        $this->assertFalse($this->done('email', $unverified, $other, null));
    }

    public function test_add_your_hotel_needs_name_city_and_website(): void
    {
        [$user, $team, $hotel] = $this->member();
        $this->assertTrue($this->done('hotel', $user, $team, $hotel));

        foreach (['city', 'website', 'name'] as $field) {
            $hotel->forceFill([$field => ''])->save();
            $this->assertFalse($this->done('hotel', $user, $team, $hotel), "no {$field}");
            $hotel->forceFill([$field => 'x'])->save();
        }

        $this->assertFalse($this->done('hotel', ...[$user, Team::factory()->create(), null]), 'a team without a hotel');
    }

    public function test_the_hotel_facts_are_done_when_the_required_facts_have_a_value(): void
    {
        [$user, $team, $hotel] = $this->member();
        $this->assertFalse($this->done('facts', $user, $team, $hotel));

        $this->facts($hotel, ['Price range per night' => '$120']);
        $this->assertFalse($this->done('facts', $user, $team, $hotel), 'only one of the two required facts');

        $this->facts($hotel, ['number of rooms' => '   ']);
        $this->assertFalse($this->done('facts', $user, $team, $hotel), 'a blank value is not a fact');

        $hotel->facts()->where('key', 'number of rooms')->update(['value' => '24']);
        $this->assertTrue($this->done('facts', $user, $team, $hotel), 'names are compared without case');

        config(['citevanta.setup_checklist.required_facts' => ['Price range per night', 'Spa']]);
        $this->assertFalse($this->done('facts', $user, $team, $hotel), 'the required facts are configurable');
    }

    public function test_competitors_need_at_least_three(): void
    {
        [$user, $team, $hotel] = $this->member();
        Competitor::factory()->count(2)->create(['hotel_id' => $hotel->id]);
        $this->assertFalse($this->done('competitors', $user, $team, $hotel));

        Competitor::factory()->create(['hotel_id' => $hotel->id]);
        $this->assertTrue($this->done('competitors', $user, $team, $hotel));

        Competitor::factory()->count(5)->create(['hotel_id' => Hotel::factory()->create()->id]);
        [, $otherTeam, $otherHotel] = $this->member();
        $this->assertFalse($this->done('competitors', $user, $otherTeam, $otherHotel), 'another hotel\'s competitors do not count');
    }

    public function test_prompts_need_ten_active_ones(): void
    {
        [$user, $team, $hotel] = $this->member();
        Prompt::factory()->count(9)->create(['hotel_id' => $hotel->id]);
        Prompt::factory()->count(4)->create(['hotel_id' => $hotel->id, 'is_active' => false]);
        $this->assertFalse($this->done('prompts', $user, $team, $hotel), '9 active, the inactive ones do not count');

        Prompt::factory()->create(['hotel_id' => $hotel->id]);
        $this->assertTrue($this->done('prompts', $user, $team, $hotel));
    }

    public function test_the_first_scan_is_done_when_one_scan_has_completed(): void
    {
        [$user, $team, $hotel] = $this->member();
        foreach (['queued', 'running', 'failed', 'paused_budget'] as $status) {
            ScanRun::factory()->create(['hotel_id' => $hotel->id, 'status' => $status]);
        }
        $this->assertFalse($this->done('scan', $user, $team, $hotel));

        ScanRun::factory()->create(['hotel_id' => $hotel->id, 'status' => 'completed_with_errors']);
        $this->assertTrue($this->done('scan', $user, $team, $hotel), 'a scan that finished with some errors still gave results');

        [, $otherTeam, $otherHotel] = $this->member();
        $this->assertFalse($this->done('scan', $user, $otherTeam, $otherHotel));
    }

    public function test_the_first_recommendation_is_done_when_it_was_acted_on(): void
    {
        [$user, $team, $hotel] = $this->member();
        Recommendation::factory()->count(2)->create(['hotel_id' => $hotel->id]);
        $this->assertFalse($this->done('recommendation', $user, $team, $hotel), 'new recommendations that nobody touched');

        foreach ([RecommendationStatus::ToDo, RecommendationStatus::InProgress, RecommendationStatus::Done, RecommendationStatus::Dismissed] as $status) {
            Recommendation::query()->where('hotel_id', $hotel->id)->update(['status' => RecommendationStatus::New->value]);
            Recommendation::query()->where('hotel_id', $hotel->id)->first()->update(['status' => $status]);

            $this->assertTrue($this->done('recommendation', $user, $team, $hotel), $status->value);
        }
    }

    public function test_the_openai_key_is_done_when_it_passed_its_test_and_a_budget_is_set(): void
    {
        [$owner, $team, $hotel] = $this->member(Plan::Private);
        $this->assertFalse($this->done('openai_key', $owner, $team, $hotel), 'no key');

        $team->forceFill(['openai_key' => 'sk-test-key-1234', 'openai_key_last4' => '1234', 'ai_budget_usd' => 50])->save();
        $this->assertFalse($this->done('openai_key', $owner, $team, $hotel), 'saved but never tested');

        $team->forceFill(['openai_key_checked_at' => now(), 'openai_key_stopped_at' => now(), 'openai_key_stop_code' => 'invalid_api_key'])->save();
        $this->assertFalse($this->done('openai_key', $owner, $team, $hotel), 'OpenAI stopped the key');

        $team->forceFill(['openai_key_stopped_at' => null, 'openai_key_stop_code' => null, 'ai_budget_usd' => 0])->save();
        $this->assertFalse($this->done('openai_key', $owner, $team, $hotel), 'no monthly budget');

        $team->forceFill(['ai_budget_usd' => 40])->save();
        $this->assertTrue($this->done('openai_key', $owner, $team, $hotel));
    }

    public function test_connect_google_is_done_when_ga4_and_search_console_are_both_connected(): void
    {
        [$owner, $team, $hotel] = $this->member(Plan::Private);
        $this->assertFalse($this->done('google', $owner, $team, $hotel), 'no link');

        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1']);
        $this->assertFalse($this->done('google', $owner, $team, $hotel), 'GA4 only');

        $link->update(['gsc_site' => 'sc-domain:nandini.example']);
        $this->assertTrue($this->done('google', $owner, $team, $hotel));
    }

    public function test_the_website_script_is_done_after_its_first_event_or_a_passed_check(): void
    {
        [$owner, $team, $hotel] = $this->member(Plan::Private);
        $this->assertFalse($this->done('script', $owner, $team, $hotel), 'no key yet');

        $tracking = HotelTracking::forHotel($hotel);
        $this->assertFalse($this->done('script', $owner, $team, $hotel), 'set up, but nothing seen and not checked');

        $tracking->update(['installed' => false, 'last_checked_at' => now()]);
        $this->assertFalse($this->done('script', $owner, $team, $hotel), 'the check did not find it');

        $tracking->update(['installed' => true]);
        $this->assertTrue($this->done('script', $owner, $team, $hotel), 'Check installation passed');

        $tracking->update(['installed' => false, 'last_seen_at' => now()]);
        $this->assertTrue($this->done('script', $owner, $team, $hotel), 'the script sent its first event');
    }

    public function test_tracking_health_is_done_when_the_check_ran_and_shows_no_error(): void
    {
        [$owner, $team, $hotel] = $this->member(Plan::Private);
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'gsc_site' => 'sc-domain:x.example']);
        $this->assertFalse($this->done('health', $owner, $team, $hotel), 'never checked');

        $link->update(['health_checked_at' => now(), 'health' => ['receiving' => ['status' => 'green', 'message' => 'ok'], 'purchases' => ['status' => 'red', 'message' => 'No purchases']]]);
        $this->assertFalse($this->done('health', $owner, $team, $hotel), 'one check is red');

        $link->update(['health' => ['receiving' => ['status' => 'green', 'message' => 'ok'], 'purchases' => ['status' => 'amber', 'message' => 'Few']]]);
        $this->assertTrue($this->done('health', $owner, $team, $hotel), 'amber is a warning, not an error');

        $this->assertFalse($this->done('health', $owner, $team, null), 'no hotel');
    }

    public function test_items_tick_by_themselves_from_real_data_and_after_livewire_actions(): void
    {
        [$user, $team, $hotel] = $this->member();

        $component = Livewire::actingAs($user)->test(SetupChecklistComponent::class);
        $component->assertSee('2 of 7 done')->assertSeeHtml('data-item="scan" data-done="0"');

        ScanRun::factory()->create(['hotel_id' => $hotel->id]);
        $component->dispatch('setup-checklist-refresh')->assertSee('3 of 7 done')->assertSeeHtml('data-item="scan" data-done="1"');
    }

    // --- the card, the pill and the saved state ----------------------------------------------------------

    public function test_the_default_is_expanded_on_the_first_visit_and_nothing_is_saved_until_the_user_acts(): void
    {
        [$user, $team] = $this->member();

        $this->assertSame(SetupChecklistState::EXPANDED, $this->checklist()->record($user, $team)->state);
        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'expanded')->assertSeeHtml('data-state="expanded"');
        $this->assertSame(0, SetupChecklistState::count());
    }

    public function test_minimize_expand_and_hide_are_remembered_per_user_and_per_team(): void
    {
        [$user, $team] = $this->member();
        $second = User::factory()->create();
        $team->addMember($second, TeamRole::Member);
        $second->switchTeam($team);
        $otherTeam = Team::factory()->create(['plan' => Plan::Starter]);
        $otherTeam->addMember($user, TeamRole::Owner);

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->call('minimize')->assertSet('state', 'minimized');
        $this->assertSame('minimized', SetupChecklistState::where(['user_id' => $user->id, 'team_id' => $team->id])->value('state'));

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'minimized')->assertSeeHtml('data-state="minimized"')->assertSee('Setup 2/7');
        Livewire::actingAs($second)->test(SetupChecklistComponent::class)->assertSet('state', 'expanded');

        $user->switchTeam($otherTeam);
        Livewire::actingAs($user->fresh())->test(SetupChecklistComponent::class)->assertSet('state', 'expanded');
        $user->switchTeam($team);

        Livewire::actingAs($user->fresh())->test(SetupChecklistComponent::class)->call('expand')->assertSet('state', 'expanded');
        $this->assertSame('expanded', SetupChecklistState::where(['user_id' => $user->id, 'team_id' => $team->id])->value('state'));

        Livewire::actingAs($user->fresh())->test(SetupChecklistComponent::class)->call('hide')->assertSet('state', 'hidden')->assertDontSeeHtml('id="setup-checklist"');
        Livewire::actingAs($user->fresh())->test(SetupChecklistComponent::class)->assertSet('state', 'hidden')->assertDontSeeHtml('id="setup-checklist"');
        $this->assertSame(1, SetupChecklistState::where(['user_id' => $user->id, 'team_id' => $team->id])->count(), 'one row for this user and team');
    }

    public function test_the_help_menu_brings_a_hidden_checklist_back(): void
    {
        [$user, $team] = $this->member();
        $this->checklist()->setState($user, $team, SetupChecklistState::HIDDEN);

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertDontSeeHtml('id="setup-checklist"')
            ->dispatch('setup-checklist-restore')->assertSet('state', 'expanded')->assertSeeHtml('id="setup-checklist"');

        $this->assertSame('expanded', $this->checklist()->record($user, $team)->state);
    }

    public function test_an_unknown_state_is_refused(): void
    {
        [$user, $team] = $this->member();

        $this->expectException(HttpException::class);
        $this->checklist()->setState($user, $team, 'floating');
    }

    // --- all set ----------------------------------------------------------------------------------------------

    public function test_when_everything_is_done_all_set_shows_on_the_next_visit_and_then_it_hides_for_good(): void
    {
        [$user, $team, $hotel] = $this->member();
        $this->completeBase($user, $team, $hotel);

        $first = Livewire::actingAs($user)->test(SetupChecklistComponent::class);
        $first->assertSet('state', 'expanded')->assertSee('All set')->assertSee('7 of 7 done')->assertSeeHtml('id="setup-checklist-all-set"');
        $this->assertSame(1, $this->checklist()->record($user, $team)->all_set_views);

        // A Livewire refresh on the same page does not count as another visit.
        $first->dispatch('setup-checklist-refresh')->assertSet('state', 'expanded');
        $this->assertSame(1, SetupChecklistState::sole()->all_set_views);

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'hidden')->assertDontSeeHtml('id="setup-checklist"');
        $this->assertSame('hidden', SetupChecklistState::sole()->state);

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'hidden');
    }

    public function test_restoring_after_all_set_shows_all_set_once_more(): void
    {
        [$user, $team, $hotel] = $this->member();
        $this->completeBase($user, $team, $hotel);
        Livewire::actingAs($user)->test(SetupChecklistComponent::class);
        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'hidden');

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->dispatch('setup-checklist-restore')->assertSet('state', 'expanded')->assertSee('All set');

        $this->assertSame(0, SetupChecklistState::sole()->all_set_views);
        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'expanded')->assertSee('All set');
        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSet('state', 'hidden');
    }

    public function test_the_minimized_pill_says_all_set_when_everything_is_done(): void
    {
        [$user, $team, $hotel] = $this->member();
        $this->completeBase($user, $team, $hotel);
        $this->checklist()->setState($user, $team, SetupChecklistState::MINIMIZED);

        Livewire::actingAs($user)->test(SetupChecklistComponent::class)->assertSee('All set');
    }

    // --- who sees it ---------------------------------------------------------------------------------------------

    public function test_it_is_not_shown_on_admin_pages_to_unverified_emails_or_users_without_a_team(): void
    {
        [$user] = $this->member();
        [$unverified] = $this->member(verified: false);

        $this->assertTrue($this->checklist()->available($user, 'hotels.show'));
        $this->assertTrue($this->checklist()->available($user, null));
        foreach (['admin.teams', 'admin.system', 'admin.integrations.google'] as $route) {
            $this->assertFalse($this->checklist()->available($user, $route), $route);
        }
        $this->assertFalse($this->checklist()->available($unverified, 'hotels.show'));
        $this->assertFalse($this->checklist()->available(User::factory()->create(), 'hotels.show'), 'no team');
        $this->assertFalse($this->checklist()->available(null, 'hotels.show'));

        Livewire::actingAs($unverified)->test(SetupChecklistComponent::class)->assertDontSeeHtml('id="setup-checklist"');
    }

    public function test_a_page_has_the_checklist_region_and_the_help_entry_and_an_admin_page_has_neither(): void
    {
        [$user, , $hotel] = $this->member(Plan::Private);

        $page = $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk();
        $page->assertSeeHtml('id="setup-checklist"')->assertSeeHtml('role="region"')->assertSeeHtml('aria-label="Setup checklist"')
            ->assertSee('Get started')->assertSee('Hide this checklist')->assertSeeHtml('data-cv-checklist-restore')->assertSeeHtml('data-item="google"')->assertSeeHtml('data-item="health"');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.teams'))->assertOk()->assertDontSeeHtml('id="setup-checklist"')->assertDontSeeHtml('data-cv-checklist-restore');
    }

    public function test_the_private_items_do_not_appear_for_other_plans_on_the_page(): void
    {
        [$user, , $hotel] = $this->member(Plan::Starter);

        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()->assertSeeHtml('data-item="scan"')->assertDontSeeHtml('data-item="google"')->assertDontSeeHtml('data-item="openai_key"')->assertDontSeeHtml('data-item="script"');
    }

    public function test_setup_checklist_enabled_false_switches_it_off_everywhere(): void
    {
        [$user, , $hotel] = $this->member();
        config(['citevanta.setup_checklist.enabled' => false]);

        $this->assertFalse($this->checklist()->enabled());
        $this->assertFalse($this->checklist()->available($user, 'hotels.show'));
        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()->assertDontSeeHtml('id="setup-checklist"')->assertDontSeeHtml('data-cv-checklist-restore');
    }

    public function test_it_is_on_by_default_and_the_key_is_in_both_env_examples(): void
    {
        $this->assertTrue(config('citevanta.setup_checklist.enabled'));

        foreach (['.env.example', '.env.production.example'] as $file) {
            $this->assertStringContainsString("SETUP_CHECKLIST_ENABLED=true\n", (string) file_get_contents(base_path($file)), $file);
        }
    }

    // --- Admin > Teams: reset -------------------------------------------------------------------------------------

    public function test_an_admin_resets_the_checklist_of_one_team_member(): void
    {
        [$owner, $team] = $this->member();
        $member = User::factory()->create();
        $team->addMember($member, TeamRole::Member);
        $this->checklist()->setState($owner, $team, SetupChecklistState::HIDDEN);
        $this->checklist()->setState($member, $team, SetupChecklistState::MINIMIZED);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.teams'))->assertOk()->assertSee('Reset checklist');
        $this->actingAs($admin)->post(route('admin.teams.reset-checklist', $team), ['user' => $owner->id])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(SetupChecklistState::EXPANDED, $this->checklist()->record($owner, $team)->state, 'back to a first visit');
        $this->assertSame(SetupChecklistState::MINIMIZED, $this->checklist()->record($member, $team)->state, 'the other member is untouched');
    }

    public function test_only_admins_reset_the_checklist_and_only_for_members_of_that_team(): void
    {
        [$owner, $team] = $this->member();
        [$stranger, $otherTeam] = $this->member();
        $this->checklist()->setState($stranger, $otherTeam, SetupChecklistState::HIDDEN);
        $admin = User::factory()->admin()->create();

        $this->actingAs($owner)->post(route('admin.teams.reset-checklist', $team), ['user' => $owner->id])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.teams.reset-checklist', $team), ['user' => $stranger->id])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.teams.reset-checklist', $team), [])->assertSessionHasErrors('user');
        $this->assertSame(SetupChecklistState::HIDDEN, $this->checklist()->record($stranger, $otherTeam)->state);
    }

    // --- texts: English and Indonesian ---------------------------------------------------------------------------------

    public function test_every_item_and_every_interface_text_exists_in_english_and_indonesian(): void
    {
        $keys = [...SetupChecklist::BASE, ...SetupChecklist::PRIVATE, 'upgrade'];

        foreach (['en', 'id'] as $locale) {
            foreach ($keys as $key) {
                foreach (['title', 'help', 'action'] as $part) {
                    $text = (string) trans("checklist.items.{$key}.{$part}", [], $locale);
                    $this->assertNotSame("checklist.items.{$key}.{$part}", $text, "{$key}.{$part} is missing in {$locale}");
                    $this->assertGreaterThan(2, mb_strlen($text), "{$key}.{$part} in {$locale}");
                }
            }
        }

        $this->assertSame(array_keys(trans('checklist.ui', [], 'en')), array_keys(trans('checklist.ui', [], 'id')), 'the same interface texts in both languages');
        $this->assertSame(array_keys(trans('checklist.items', [], 'en')), array_keys(trans('checklist.items', [], 'id')));
        $this->assertSame(array_keys(trans('checklist.items', [], 'en')), $keys);
        $this->assertNotSame(trans('checklist.items.competitors.title', [], 'en'), trans('checklist.items.competitors.title', [], 'id'));
        $this->assertTrue(Lang::has('checklist.ui.help_entry', 'id', false));
    }

    public function test_the_card_is_written_in_the_current_language(): void
    {
        [$user, , $hotel] = $this->member();
        app()->setLocale('id');

        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()
            ->assertSee('Mulai di sini')->assertSee('2 dari 7 selesai')->assertSee('Tambahkan minimal 3 pesaing')->assertSee('Sembunyikan daftar ini')->assertSee('Daftar periksa pengaturan')->assertDontSee('Get started');
    }

    // --- the look: z-index, tours, narrow screens, motion -------------------------------------------------------------------

    public function test_the_styles_keep_it_below_modals_hide_it_during_tours_and_leave_room_for_the_page(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/_components.scss'));
        preg_match('/\.cv-checklist \{[^}]*z-index: (\d+);/s', $scss, $z);

        $this->assertLessThan(1055, (int) $z[1], 'below Bootstrap modals (1055)');
        $this->assertLessThan(3000, (int) $z[1], 'below the tour');
        $this->assertStringContainsString('.cv-tour-active .cv-checklist { display: none !important; }', $scss);
        $this->assertStringContainsString('body:has(.cv-checklist) .cv-footer { margin-bottom: 4.5rem; }', $scss, 'room for pagination and Save buttons');
        $this->assertStringContainsString('body:has(.cv-checklist[data-state="expanded"]) .cv-footer { margin-bottom: 28rem; }', $scss);
        $this->assertMatchesRegularExpression('/max-width: 991\.98px\) \{\s*\.cv-checklist__card\.is-sheet \{[^}]*bottom: 0;[^}]*border-radius: \.75rem \.75rem 0 0;/s', $scss, 'a bottom sheet on a narrow screen');
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\) \{\s*\.cv-checklist \* \{ transition: none !important; animation: none !important; \}/', $scss);
        $this->assertStringContainsString(':is(button, a):focus-visible', $scss);
        $this->assertStringContainsString('$cv-header-bg', $scss);
    }

    public function test_the_markup_has_a_pill_a_card_a_menu_and_labelled_controls(): void
    {
        [$user, , $hotel] = $this->member();

        $html = $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()->getContent();

        foreach (['id="setup-checklist-pill"', 'id="setup-checklist-card"', 'id="setup-checklist-minimize"', 'id="setup-checklist-hide"', 'aria-label="Minimize the checklist"', 'aria-label="Checklist menu"', 'aria-label="Open the setup checklist"', 'role="progressbar"', '@keydown.escape.window', 'max-width: 991.98px', 'cv-checklist__ring', 'Setup 2/7'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('narrow', $html);
    }

    public function test_a_tour_hides_the_checklist_while_it_runs_and_restores_it_afterwards(): void
    {
        $js = (string) file_get_contents(resource_path('js/tour.js'));
        $this->assertStringContainsString("root.classList.add('cv-tour-active')", $js);
        $this->assertStringContainsString("root.classList.remove('cv-tour-active')", $js);

        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        [$user] = $this->member();
        $data = app(TourService::class)->forPage($user, 'hotels.show');
        $data['urls'] = ['finish' => '/tours/dashboard/finish', 'skip' => '/tours/dashboard/skip', 'reset' => '/tours/reset'];
        $elements = [];
        foreach ($data['steps'] as $step) {
            $elements[$step['target']] = ['rect' => ['top' => 100, 'left' => 80, 'width' => 200, 'height' => 60]];
        }

        $run = Process::path(base_path())->run(['node', base_path('tests/Fixtures/tour-harness.mjs'), json_encode(['data' => $data, 'urls' => [], 'elements' => $elements, 'actions' => ['skip']])]);
        $out = json_decode($run->output(), true);

        $this->assertTrue($run->successful(), $run->errorOutput());
        $this->assertTrue($out['steps'][0]['tourActive'], 'the checklist is hidden while the tour runs');
        $this->assertFalse($out['tourActive'], 'and back when it ends');
    }

    public function test_the_help_script_asks_for_a_refresh_after_livewire_actions(): void
    {
        $js = (string) file_get_contents(resource_path('js/checklist.js'));

        $this->assertStringContainsString('setup-checklist-refresh', $js);
        $this->assertStringContainsString('setup-checklist-restore', $js);
        $this->assertStringContainsString("Livewire.hook('commit'", $js);
        $this->assertStringContainsString('data-cv-checklist-restore', $js);
    }
}
