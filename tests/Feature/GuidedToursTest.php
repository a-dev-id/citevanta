<?php

namespace Tests\Feature;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Hotel;
use App\Models\Team;
use App\Models\User;
use App\Models\UserTour;
use App\Services\Tours\TourDefinitions;
use App\Services\Tours\TourService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guided tours: what each page offers, who gets it by itself, what is remembered per user, plan filtering,
 * TOURS_ENABLED, the admin reset, and the browser side (resources/js/tour.js, run in Node against a fake DOM).
 */
class GuidedToursTest extends TestCase
{
    use RefreshDatabase;

    private function tours(): TourService
    {
        return app(TourService::class);
    }

    /** @return array{0: User, 1: Team, 2: Hotel} */
    private function member(Plan $plan = Plan::Private, bool $setUp = true, bool $verified = true): array
    {
        $team = Team::factory()->create(['plan' => $plan]);
        $user = User::factory()->when(! $verified, fn ($factory) => $factory->unverified())->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['setup_completed_at' => $setUp ? now() : null]);

        return [$user->fresh(), $team, $hotel];
    }

    // --- the tours themselves ----------------------------------------------------------------

    public function test_every_tour_has_4_to_7_steps_with_english_and_indonesian_text(): void
    {
        $this->assertSame(['dashboard', 'recommendations', 'prompts-scans', 'reports', 'settings'], array_keys(TourDefinitions::all()));

        foreach (TourDefinitions::all() as $key => $tour) {
            $ids = array_column($tour['steps'], 'id');
            $this->assertGreaterThanOrEqual(4, count($ids), $key);
            $this->assertLessThanOrEqual(7, count($ids), $key);
            $this->assertSame($ids, array_values(array_unique($ids)), "{$key}: step ids are unique");
            $this->assertGreaterThanOrEqual(1, $tour['version']);

            foreach (['en', 'id'] as $locale) {
                $this->assertTrue(Lang::has("tours.{$key}.name", $locale, false), "{$key} name in {$locale}");

                foreach ($ids as $id) {
                    foreach (['title', 'text'] as $part) {
                        $text = (string) trans("tours.{$key}.steps.{$id}.{$part}", [], $locale);
                        $this->assertNotSame("tours.{$key}.steps.{$id}.{$part}", $text, "{$key}.{$id}.{$part} is missing in {$locale}");
                        $this->assertGreaterThan($part === 'title' ? 3 : 20, mb_strlen($text), "{$key}.{$id}.{$part} in {$locale}");
                    }
                }
            }
        }

        $this->assertSame(array_keys(trans('tours.ui', [], 'en')), array_keys(trans('tours.ui', [], 'id')), 'the same interface texts in both languages');
        $this->assertNotSame(trans('tours.dashboard.steps.score.text', [], 'en'), trans('tours.dashboard.steps.score.text', [], 'id'));
    }

    public function test_the_payload_is_written_in_the_users_language(): void
    {
        [$user] = $this->member();

        app()->setLocale('id');
        $id = $this->tours()->forPage($user, 'hotels.show');
        app()->setLocale('en');
        $en = $this->tours()->forPage($user, 'hotels.show');

        $this->assertSame('Ganti hotel', $id['steps'][0]['title']);
        $this->assertSame('Switch hotel', $en['steps'][0]['title']);
        $this->assertSame('Sebelumnya', $id['ui']['previous']);
        $this->assertSame(':current of :total', $en['ui']['counter']);
        $this->assertSame(':current dari :total', $id['ui']['counter']);
    }

    public function test_the_steps_point_at_elements_that_exist_in_the_views(): void
    {
        $views = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views .= file_get_contents($file->getPathname());
            }
        }

        foreach (TourDefinitions::all() as $key => $tour) {
            foreach ($tour['steps'] as $step) {
                $target = $step['target'];

                if (preg_match('/^\[data-tour="(.+)"\]$/', $target, $m)) {
                    $name = str_starts_with($m[1], 'nav-') ? 'data-tour="nav-{{ $item->key }}"' : 'data-tour="'.$m[1].'"';
                    $this->assertStringContainsString($name, $views, "{$key}.{$step['id']}: {$target}");
                } elseif (preg_match('/^#([\w-]+)$/', $target, $m)) {
                    $this->assertStringContainsString('id="'.$m[1].'"', $views, "{$key}.{$step['id']}: {$target}");
                } else {
                    $this->fail("{$key}.{$step['id']}: unexpected selector {$target}");
                }
            }
        }
    }

    // --- which tour a page has, and when it starts by itself -------------------------------------

    public function test_pages_get_their_tour_and_admin_and_other_pages_get_none(): void
    {
        [$user] = $this->member();

        foreach (['hotels.show' => 'dashboard', 'hotels.recommendations' => 'recommendations', 'hotels.prompts' => 'prompts-scans', 'hotels.scans' => 'prompts-scans', 'hotels.reports' => 'reports', 'settings.ai-key' => 'settings', 'settings.google' => 'settings'] as $route => $tour) {
            $this->assertSame($tour, $this->tours()->forPage($user, $route)['key'], $route);
        }

        foreach (['admin.teams', 'admin.system', 'admin.integrations.google', 'hotels.index', 'settings.profile', 'hotels.competitors', null] as $route) {
            $this->assertNull($this->tours()->forPage($user, $route), (string) $route);
        }
    }

    public function test_a_tour_starts_by_itself_only_for_a_verified_user_whose_team_is_set_up(): void
    {
        [$ready] = $this->member();
        $this->assertTrue($this->tours()->forPage($ready, 'hotels.show')['autostart']);

        [$unverified] = $this->member(verified: false);
        $this->assertFalse($this->tours()->forPage($unverified, 'hotels.show')['autostart'], 'the email is not verified yet');
        $this->assertNotNull($this->tours()->forPage($unverified, 'hotels.show'), 'but the tour can still be replayed by hand');

        [$notSetUp] = $this->member(setUp: false);
        $this->assertFalse($this->tours()->forPage($notSetUp, 'hotels.show')['autostart'], 'the team has no hotel that is set up');
    }

    public function test_the_page_carries_the_tour_data_and_the_help_menu(): void
    {
        [$user, , $hotel] = $this->member();

        $page = $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk();

        $page->assertSee('id="cv-tour-data"', false)->assertSee('data-tour="scans-list"', false)->assertSee('data-cv-tour-start', false)->assertSee('data-cv-tour-restart', false)
            ->assertSee('Take the tour of this page')->assertSee('Restart all tours')->assertSee('data-tour="help"', false);
        preg_match('/<script type="application\/json" id="cv-tour-data">(.*?)<\/script>/s', $page->getContent(), $m);
        $data = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(['prompts-scans', true, 5], [$data['key'], $data['autostart'], count($data['steps'])]);
        $this->assertSame(route('tours.skip', 'prompts-scans'), $data['urls']['skip']);

        $this->actingAs($user)->get(route('hotels.index'))->assertOk()->assertDontSee('id="cv-tour-data"', false)->assertSee('No tour for this page');
    }

    public function test_the_help_menu_and_texts_follow_the_language(): void
    {
        [$user, , $hotel] = $this->member();
        app()->setLocale('id');

        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()->assertSee('Ikuti tur halaman ini')->assertSee('Mulai ulang semua tur')->assertSee('Apa itu prompt', false)->assertDontSee('Take the tour of this page');
    }

    // --- plan filtering ---------------------------------------------------------------------------------

    public function test_private_plan_tours_and_steps_are_only_for_private_teams(): void
    {
        [$private] = $this->member(Plan::Private);
        [$starter] = $this->member(Plan::Starter);
        [$trial] = $this->member(Plan::Trial);

        foreach (['hotels.reports', 'settings.ai-key', 'settings.google'] as $route) {
            $this->assertNotNull($this->tours()->forPage($private, $route), $route);
            $this->assertNull($this->tours()->forPage($starter, $route), "{$route} for Starter");
            $this->assertNull($this->tours()->forPage($trial, $route), "{$route} for the trial");
        }

        $this->assertSame(['date_picker', 'compare', 'kpi', 'tables', 'csv', 'searches'], array_column($this->tours()->forPage($private, 'hotels.reports')['steps'], 'id'));
        $this->assertSame(['ai_key', 'budget', 'google_share', 'google_pick', 'google_menu'], array_column($this->tours()->forPage($private, 'settings.ai-key')['steps'], 'id'));
    }

    public function test_steps_about_locked_features_are_for_the_trial_plan_only(): void
    {
        [$trial] = $this->member(Plan::Trial);
        [$starter] = $this->member(Plan::Starter);
        [$private] = $this->member(Plan::Private);

        $ids = fn (User $user, string $route) => array_column($this->tours()->forPage($user, $route)['steps'], 'id');

        $this->assertContains('trial', $ids($trial, 'hotels.show'));
        $this->assertContains('locked', $ids($trial, 'hotels.recommendations'));
        foreach ([$starter, $private] as $other) {
            $this->assertNotContains('trial', $ids($other, 'hotels.show'));
            $this->assertNotContains('locked', $ids($other, 'hotels.recommendations'));
        }
        $this->assertSame(['switcher', 'score', 'trend', 'last_scan', 'menu', 'help'], $ids($starter, 'hotels.show'));
    }

    // --- memory -------------------------------------------------------------------------------------------

    public function test_done_finishes_a_tour_and_a_new_user_is_offered_every_tour(): void
    {
        [$user] = $this->member();

        $this->assertTrue($this->tours()->isOffered($user, 'dashboard'));

        $this->tours()->finish($user, 'dashboard');

        $this->assertFalse($this->tours()->isOffered($user, 'dashboard'));
        $this->assertTrue($this->tours()->isOffered($user, 'reports'), 'other tours are not affected');
        $this->assertFalse($this->tours()->forPage($user, 'hotels.show')['autostart']);
        $record = UserTour::sole();
        $this->assertSame([UserTour::FINISHED, 1, 0], [$record->status, $record->version, $record->skips]);
        $this->assertNotNull($record->finished_at);
    }

    public function test_closing_with_dont_show_again_finishes_the_tour(): void
    {
        [$user] = $this->member();

        $this->tours()->skip($user, 'reports', dontShowAgain: true);

        $this->assertFalse($this->tours()->isOffered($user, 'reports'));
        $this->assertSame(UserTour::FINISHED, UserTour::sole()->status);
    }

    public function test_a_skipped_tour_comes_back_at_most_two_more_times_and_then_stops(): void
    {
        [$user] = $this->member();
        $offered = [];

        for ($visit = 1; $visit <= 5; $visit++) {
            $offered[$visit] = $this->tours()->isOffered($user, 'recommendations');

            if ($offered[$visit]) {
                $this->tours()->skip($user, 'recommendations', dontShowAgain: false);
            }
        }

        $this->assertSame([1 => true, 2 => true, 3 => true, 4 => false, 5 => false], $offered, 'shown on the first visit and at most 2 more times');
        $record = UserTour::sole();
        $this->assertSame([UserTour::SKIPPED, 3], [$record->status, $record->skips]);
    }

    public function test_finishing_after_a_skip_ends_it_and_skipping_a_replay_does_not_undo_finished(): void
    {
        [$user] = $this->member();

        $this->tours()->skip($user, 'dashboard', false);
        $this->assertTrue($this->tours()->isOffered($user, 'dashboard'));

        $this->tours()->finish($user, 'dashboard');
        $this->assertFalse($this->tours()->isOffered($user, 'dashboard'));

        // The user replays the tour from the help menu and skips it: they already finished it.
        $this->tours()->skip($user, 'dashboard', false);
        $this->assertFalse($this->tours()->isOffered($user, 'dashboard'));
        $this->assertSame(UserTour::FINISHED, UserTour::sole()->status);
    }

    public function test_memory_is_per_user_not_per_team_or_device(): void
    {
        [$one, $team] = $this->member();
        $two = User::factory()->create();
        $team->addMember($two, TeamRole::Member);

        $this->tours()->finish($one, 'dashboard');

        $this->assertFalse($this->tours()->isOffered($one, 'dashboard'));
        $this->assertTrue($this->tours()->isOffered($two, 'dashboard'));
        $this->assertSame(1, UserTour::where('user_id', $one->id)->count());
    }

    public function test_a_new_version_of_a_tour_is_offered_once_more(): void
    {
        [$user] = $this->member();
        UserTour::create(['user_id' => $user->id, 'tour' => 'dashboard', 'version' => 0, 'status' => UserTour::FINISHED, 'skips' => 0, 'finished_at' => now()->subYear()]);

        $this->assertTrue($this->tours()->isOffered($user, 'dashboard'), 'finished an older version');
        $this->assertTrue($this->tours()->forPage($user, 'hotels.show')['autostart']);

        $this->tours()->finish($user, 'dashboard');

        $this->assertFalse($this->tours()->isOffered($user, 'dashboard'), 'once: the new version is finished now');
        $this->assertSame(TourDefinitions::all()['dashboard']['version'], UserTour::sole()->version);
    }

    public function test_skips_of_an_older_version_do_not_count_against_the_new_one(): void
    {
        [$user] = $this->member();
        UserTour::create(['user_id' => $user->id, 'tour' => 'reports', 'version' => 0, 'status' => UserTour::SKIPPED, 'skips' => 3]);

        $this->assertTrue($this->tours()->isOffered($user, 'reports'));

        $this->tours()->skip($user, 'reports', false);

        $this->assertSame([1, 1], [UserTour::sole()->version, UserTour::sole()->skips], 'counting starts again for the new version');
    }

    public function test_reset_offers_every_tour_again_to_that_user_only(): void
    {
        [$user, $team] = $this->member();
        $other = User::factory()->create();
        $team->addMember($other, TeamRole::Member);
        foreach ([$user, $other] as $who) {
            $this->tours()->finish($who, 'dashboard');
            $this->tours()->finish($who, 'reports');
        }

        $this->assertSame(2, $this->tours()->reset($user));

        $this->assertTrue($this->tours()->isOffered($user, 'dashboard'));
        $this->assertFalse($this->tours()->isOffered($other, 'dashboard'));
    }

    // --- the endpoints ---------------------------------------------------------------------------------------------

    public function test_the_browser_reports_done_skip_and_restart_all(): void
    {
        [$user] = $this->member();

        $this->actingAs($user)->postJson(route('tours.skip', 'prompts-scans'), ['version' => 1, 'dont_show' => false])->assertNoContent();
        $this->assertSame([UserTour::SKIPPED, 1], [UserTour::sole()->status, UserTour::sole()->skips]);

        $this->actingAs($user)->postJson(route('tours.skip', 'prompts-scans'), ['dont_show' => true])->assertNoContent();
        $this->assertSame(UserTour::FINISHED, UserTour::sole()->status);

        $this->actingAs($user)->postJson(route('tours.finish', 'dashboard'), ['version' => 1])->assertNoContent();
        $this->assertSame(2, UserTour::count());

        $this->actingAs($user)->postJson(route('tours.reset'))->assertNoContent();
        $this->assertSame(0, UserTour::count());
    }

    public function test_the_endpoints_need_a_login_and_know_only_real_tours(): void
    {
        [$user] = $this->member();

        $this->postJson(route('tours.finish', 'dashboard'))->assertUnauthorized();
        $this->postJson(route('tours.reset'))->assertUnauthorized();
        $this->actingAs($user)->postJson(route('tours.finish', 'nope'))->assertNotFound();
        $this->actingAs($user)->postJson(route('tours.skip', 'nope'))->assertNotFound();
        $this->assertSame(0, UserTour::count());
    }

    public function test_only_the_tour_state_is_stored_nothing_about_what_the_user_did(): void
    {
        $columns = Schema::getColumnListing('user_tours');

        $this->assertEqualsCanonicalizing(['id', 'user_id', 'tour', 'version', 'status', 'skips', 'finished_at', 'created_at', 'updated_at'], $columns);
    }

    // --- TOURS_ENABLED --------------------------------------------------------------------------------------------------

    public function test_tours_enabled_false_switches_every_tour_and_the_help_menu_off(): void
    {
        [$user, , $hotel] = $this->member();
        config(['citevanta.tours.enabled' => false]);

        $this->assertNull($this->tours()->forPage($user, 'hotels.show'));
        $this->assertFalse($this->tours()->enabled());

        $this->actingAs($user)->get(route('hotels.scans', $hotel))->assertOk()
            ->assertDontSee('id="cv-tour-data"', false)->assertDontSee('data-cv-tour-start', false)->assertDontSee('Take the tour of this page');
    }

    public function test_tours_are_on_by_default_and_the_key_is_in_both_env_examples(): void
    {
        $this->assertTrue(config('citevanta.tours.enabled'));

        foreach (['.env.example', '.env.production.example'] as $file) {
            $this->assertStringContainsString("TOURS_ENABLED=true\n", (string) file_get_contents(base_path($file)), $file);
        }
    }

    // --- Admin > Teams: reset tours ----------------------------------------------------------------------------------------

    public function test_an_admin_resets_the_tours_of_one_team_member(): void
    {
        [$owner, $team] = $this->member();
        $member = User::factory()->create();
        $team->addMember($member, TeamRole::Member);
        foreach ([$owner, $member] as $who) {
            $this->tours()->finish($who, 'dashboard');
        }
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.teams'))->assertOk()->assertSee('Reset tours')->assertSee($member->name);
        $this->actingAs($admin)->post(route('admin.teams.reset-tours', $team), ['user' => $member->id])->assertRedirect()->assertSessionHas('status');

        $this->assertTrue($this->tours()->isOffered($member, 'dashboard'));
        $this->assertFalse($this->tours()->isOffered($owner, 'dashboard'), 'the other member is untouched');
    }

    public function test_only_admins_can_reset_tours_and_only_for_members_of_that_team(): void
    {
        [$owner, $team] = $this->member();
        [$stranger] = $this->member();
        $this->tours()->finish($stranger, 'dashboard');
        $admin = User::factory()->admin()->create();

        $this->actingAs($owner)->post(route('admin.teams.reset-tours', $team), ['user' => $owner->id])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.teams.reset-tours', $team), ['user' => $stranger->id])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.teams.reset-tours', $team), [])->assertSessionHasErrors('user');
        $this->assertFalse($this->tours()->isOffered($stranger, 'dashboard'), 'a user of another team is not reset');
    }

    // --- the browser side (Node, fake DOM) -------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function browser(array $scenario): array
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $result = Process::path(base_path())->run(['node', base_path('tests/Fixtures/tour-harness.mjs'), json_encode($scenario)]);
        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        return json_decode($result->output(), true);
    }

    /**
     * @param  list<string>  $present  the step ids whose element is on the page
     * @return array<string, mixed>
     */
    private function scenario(array $present = ['switcher', 'score', 'trend', 'last_scan', 'menu', 'help'], array $actions = [], array $extra = []): array
    {
        [$user] = $this->member(Plan::Starter);
        $data = $this->tours()->forPage($user, 'hotels.show');
        $data['urls'] = ['finish' => '/tours/dashboard/finish', 'skip' => '/tours/dashboard/skip', 'reset' => '/tours/reset'];
        $elements = [];

        foreach ($data['steps'] as $step) {
            if (in_array($step['id'], $present, true)) {
                $elements[$step['target']] = ['rect' => ['top' => 120, 'left' => 80, 'width' => 220, 'height' => 70]];
            }
        }

        return ['data' => $data, 'urls' => ['reset' => '/tours/reset', 'restarted' => 'Restarted'], 'elements' => $elements, 'actions' => $actions] + $extra;
    }

    public function test_steps_whose_element_is_missing_or_hidden_are_skipped_and_the_counter_follows(): void
    {
        $scenario = $this->scenario(['trend', 'menu', 'help']);
        $scenario['elements']['[data-tour="visibility"]'] = ['rect' => ['top' => 0, 'left' => 0, 'width' => 0, 'height' => 0]]; // there, but hidden

        $scenario['actions'] = ['next', 'next'];
        $run = $this->browser($scenario);

        $this->assertTrue($run['initial']['layer']);
        $this->assertSame(['The trend', '1 of 3', 'Next'], [$run['initial']['title'], $run['initial']['counter'], $run['initial']['next']], 'the switcher, the hidden score and the last scan are skipped');
        $this->assertSame(['Your menu', '2 of 3'], [$run['steps'][1]['title'], $run['steps'][1]['counter']]);
        $this->assertSame(['Help is always here', '3 of 3', 'Done'], [$run['steps'][2]['title'], $run['steps'][2]['counter'], $run['steps'][2]['next']]);
    }

    public function test_a_selector_the_browser_does_not_understand_is_just_a_skipped_step(): void
    {
        $scenario = $this->scenario(['trend', 'help']);
        $scenario['data']['steps'][1]['target'] = 'div:has(> .x)'; // an old browser without :has()

        $run = $this->browser($scenario);

        $this->assertSame(['The trend', '1 of 2'], [$run['initial']['title'], $run['initial']['counter']]);
    }

    public function test_a_tour_with_no_element_on_the_page_does_not_start_and_replay_says_so(): void
    {
        $quiet = $this->browser($this->scenario([]));
        $this->assertFalse($quiet['initial']['layer']);
        $this->assertSame([], $quiet['toasts']);

        $replay = $this->browser($this->scenario([], ['start']));
        $this->assertFalse($replay['layer']);
        $this->assertSame(['Nothing to show on this page right now.'], $replay['toasts']);
    }

    public function test_next_previous_and_done_and_the_arrow_keys_move_between_steps(): void
    {
        $run = $this->browser($this->scenario(['trend', 'menu', 'help'], ['next', 'prev', 'right', 'right', 'left']));

        $this->assertSame('1 of 3', $run['steps'][0]['counter']);
        $this->assertSame(['2 of 3', '1 of 3', '2 of 3', '3 of 3', '2 of 3'], array_column(array_slice($run['steps'], 1), 'counter'));
        $this->assertTrue($run['layer']);
        $this->assertSame(1, $run['prevHidden'] === false ? 1 : 0, 'Previous is there after the first step');

        $first = $this->browser($this->scenario(['trend', 'menu'], []));
        $this->assertTrue($first['prevHidden'], 'no Previous on the first step');
    }

    public function test_done_on_the_last_step_tells_the_server_the_tour_is_finished(): void
    {
        $run = $this->browser($this->scenario(['trend', 'help'], ['next', 'next']));

        $this->assertFalse($run['layer'], 'the tour is closed');
        $this->assertCount(1, $run['requests']);
        $this->assertSame('/tours/dashboard/finish', $run['requests'][0]['url']);
        $this->assertSame(['version' => 1], $run['requests'][0]['body']);
        $this->assertSame('token-1', $run['requests'][0]['headers']['X-CSRF-TOKEN']);
    }

    public function test_skip_esc_and_the_x_report_a_skip_and_the_checkbox_decides_whether_it_comes_back(): void
    {
        foreach (['skip', 'esc', 'close'] as $how) {
            $plain = $this->browser($this->scenario(['trend', 'help'], [$how]));
            $this->assertFalse($plain['layer'], $how);
            $this->assertSame([['url' => '/tours/dashboard/skip', 'body' => ['version' => 1, 'dont_show' => false]]], array_map(fn ($r) => ['url' => $r['url'], 'body' => $r['body']], $plain['requests']), $how);

            $ticked = $this->browser($this->scenario(['trend', 'help'], ['tick', $how]));
            $this->assertTrue($ticked['requests'][0]['body']['dont_show'], "{$how} with the checkbox ticked");
        }

        $done = $this->browser($this->scenario(['trend', 'help'], ['next', 'tick', 'next']));
        $this->assertSame('/tours/dashboard/finish', $done['requests'][0]['url'], 'Done always finishes');
    }

    public function test_focus_moves_into_the_popover_stays_there_and_the_dialog_is_labelled(): void
    {
        $run = $this->browser($this->scenario(['trend', 'menu', 'help'], ['focus-last', 'tab', 'shifttab']));

        $this->assertStringContainsString('btn-primary', $run['steps'][0]['focus'], 'focus starts on the Next button');
        $this->assertStringContainsString('cv-tour__close', $run['steps'][2]['focus'], 'Tab from the last control goes back to the first');
        $this->assertStringContainsString('btn-primary', $run['steps'][3]['focus'], 'Shift+Tab from the first goes to the last');
        $this->assertSame(['modal' => 'true', 'labelledby' => 'cv-tour-title'], $run['aria']);
        $this->assertSame('Guided tour: 1 of 3', $run['label']);
    }

    public function test_the_outline_and_popover_are_placed_next_to_the_element_inside_the_screen(): void
    {
        $run = $this->browser($this->scenario(['trend'], []));

        $this->assertSame(['114px', '74px', '232px', '82px'], [$run['spot']['top'], $run['spot']['left'], $run['spot']['width'], $run['spot']['height']], 'a 6px margin around the element');
        $this->assertSame('340px', $run['pop']['width']);
        $this->assertGreaterThanOrEqual(12, (int) $run['pop']['left']);
        $this->assertGreaterThan(114 + 82, (int) $run['pop']['top'], 'the popover sits below the element');
    }

    public function test_a_tour_never_starts_by_itself_on_a_modal_or_unsaved_input_but_can_be_started_by_hand(): void
    {
        foreach (['modal', 'dirtyForm', 'focusedInput'] as $block) {
            $blocked = $this->browser($this->scenario(['trend', 'help'], [], [$block => true]));
            $this->assertFalse($blocked['initial']['layer'], "{$block}: no automatic start");
            $this->assertSame([], $blocked['requests']);

            $byHand = $this->browser($this->scenario(['trend', 'help'], ['start'], [$block => true]));
            $this->assertTrue($byHand['layer'], "{$block}: the help menu still starts it");
        }
    }

    public function test_a_tour_does_not_start_by_itself_when_the_server_says_no_and_start_works_from_the_help_menu(): void
    {
        $scenario = $this->scenario(['trend', 'help'], ['start']);
        $scenario['data']['autostart'] = false;
        $scenario['autostart'] = false;

        $run = $this->browser($scenario);

        $this->assertFalse($run['initial']['layer']);
        $this->assertTrue($run['layer']);
        $this->assertSame('1 of 2', $run['counter']);
    }

    public function test_restart_all_tours_asks_the_server_and_starts_this_pages_tour_again(): void
    {
        $scenario = $this->scenario(['trend', 'help'], ['restart', ['wait' => 200]]);
        $scenario['data']['autostart'] = false;
        $scenario['autostart'] = false;

        $run = $this->browser($scenario);

        $this->assertSame('/tours/reset', $run['requests'][0]['url']);
        $this->assertSame(['Restarted'], $run['toasts']);
        $this->assertTrue($run['layer']);
    }

    public function test_the_sidebar_drawer_opens_on_a_narrow_screen_for_a_sidebar_step_and_closes_again(): void
    {
        $run = $this->browser($this->scenario(['trend', 'menu', 'help'], ['next', 'next'], ['narrow' => true]));

        $this->assertSame([false, true, false], array_column(array_slice($run['steps'], 0, 3), 'drawerOpen'));
        $this->assertSame('Your menu', $run['steps'][1]['title']);
        $this->assertTrue($run['steps'][1]['drawerOpen'], 'the drawer is open for the menu step');
        $this->assertFalse($run['steps'][2]['drawerOpen'], 'and closed for the next step');
        $this->assertSame(2, $run['drawerClicks']);

        $wide = $this->browser($this->scenario(['trend', 'menu', 'help'], ['next']));
        $this->assertSame(0, $wide['drawerClicks'], 'a wide screen already shows the sidebar');
    }

    public function test_a_sidebar_step_inside_a_collapsed_submenu_is_skipped_on_a_narrow_screen(): void
    {
        $scenario = $this->scenario(['trend', 'help'], [], ['narrow' => true]);
        $scenario['elements']['#cv-sidebar'] = ['rect' => ['top' => 0, 'left' => 0, 'width' => 260, 'height' => 700], 'hiddenAncestor' => true];

        $run = $this->browser($scenario);

        $this->assertSame('1 of 2', $run['initial']['counter']);
        $this->assertSame(0, $run['drawerClicks']);
    }

    public function test_the_drawer_is_closed_when_the_tour_ends_on_a_sidebar_step(): void
    {
        $run = $this->browser($this->scenario(['menu'], ['skip'], ['narrow' => true]));

        $this->assertSame(2, $run['drawerClicks'], 'opened for the step, closed when the tour ended');
        $this->assertFalse($run['drawerOpen']);
    }

    public function test_the_stylesheet_respects_reduced_motion_and_the_script_has_no_tracking(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/_components.scss'));
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $scss);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion: reduce\) \{\s*\.cv-tour__spot,\s*\.cv-tour__pop \{ transition: none; \}/', $scss);
        $this->assertStringContainsString(':focus-visible', $scss);

        $js = (string) file_get_contents(resource_path('js/tour.js'));
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        foreach (['document.cookie', 'localStorage', 'sessionStorage', 'sendBeacon', 'navigator.userAgent', 'gtag', 'dataLayer'] as $never) {
            $this->assertStringNotContainsString($never, $js, "tour.js must not use {$never}");
        }
    }
}
