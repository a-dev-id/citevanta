<?php

namespace Tests\Feature\Trials;

use App\Enums\AiCallKind;
use App\Enums\ContentType;
use App\Enums\Plan;
use App\Enums\PromptSource;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\TeamRole;
use App\Livewire\Hotels\RecommendationsPage;
use App\Mail\TrialApproved;
use App\Models\AccuracyIssue;
use App\Models\AiUsage;
use App\Models\Audit;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Team;
use App\Models\User;
use App\Services\Content\ContentLimitReached;
use App\Services\Content\ContentService;
use App\Services\Hotels\HotelSetupService;
use App\Services\Trials\TrialHotelGuard;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

/**
 * Trial lite: abuse rules at registration, one trial per hotel, holds until
 * verified and approved, the free audit as the first scan, locked extras.
 */
class TrialLiteTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['citevanta.trial.requires_approval' => false]);
    }

    /** @return array<string, string> */
    private function registration(string $email, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Owner', 'email' => $email, 'team_name' => 'Alaya Resort Ubud',
            'password' => 'a-Long-password-123', 'password_confirmation' => 'a-Long-password-123',
        ];
    }

    private function register(string $email, string $ip = '10.0.0.1', array $overrides = [])
    {
        auth()->logout();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('register'), $this->registration($email, $overrides));
    }

    private function trialHotel(bool $verified = true): array
    {
        $team = Team::factory()->plan(Plan::Trial)->create();
        $owner = User::factory()->state(['email_verified_at' => $verified ? now() : null])->withTeam($team)->create();
        $hotel = $this->scannableHotel(prompts: 2, plan: Plan::Trial);
        $hotel->update(['team_id' => $team->id, 'setup_completed_at' => null]);

        return [$owner, $hotel->fresh()];
    }

    public function test_disposable_email_domains_cannot_start_a_trial(): void
    {
        $this->register('owner@mailinator.com')->assertSessionHasErrors(['email' => 'Please use your work email address. Temporary email addresses cannot start a trial.']);
        $this->register('owner@eu.yopmail.com')->assertSessionHasErrors('email');
        $this->register('owner@alaya.test')->assertSessionHasNoErrors();

        $this->assertSame(1, User::count());
    }

    public function test_at_most_2_trials_per_ip_in_30_days(): void
    {
        $this->register('a@one.test')->assertSessionHasNoErrors();
        $this->register('b@two.test')->assertSessionHasNoErrors();
        $this->register('c@three.test')->assertSessionHasErrors(['email' => 'Too many trials were started from your network recently. Contact us and we will set up your account.']);
        $this->register('d@four.test', '10.0.0.2')->assertSessionHasNoErrors();

        $this->travel(31)->days();
        $this->register('e@five.test')->assertSessionHasNoErrors();
        $this->assertNotNull(Team::latest('id')->first()->signup_ip_hash);
        $this->assertStringNotContainsString('10.0.0.1', (string) Team::latest('id')->first()->signup_ip_hash);
    }

    public function test_registration_checks_turnstile_when_keys_are_set_and_bots_filling_the_honeypot_are_refused(): void
    {
        $this->register('bot@x.test', overrides: ['fax' => 'spam'])->assertSessionHasErrors('email');

        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get(route('register'))->assertSee('data-sitekey="site"', false);
        $this->register('a@x.test', overrides: ['cf-turnstile-response' => 'bad'])->assertSessionHasErrors(['cf-turnstile-response' => 'Please confirm you are not a robot, then send the form again.']);
        $this->register('a@x.test', overrides: ['cf-turnstile-response' => 'good'])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request['secret'] === 'secret' && $request['response'] === 'good');
    }

    public function test_one_trial_per_hotel_including_hotels_of_paid_teams(): void
    {
        $paid = $this->scannableHotel(attributes: ['name' => 'Alaya Resort Ubud', 'city' => 'Ubud']);
        [, $hotel] = $this->trialHotel();
        $team = $hotel->team;
        $hotel->delete(); // the trial allows one hotel
        $setup = app(HotelSetupService::class);
        $details = ['city' => 'Ubud', 'country' => 'Indonesia', 'hotel_type' => 'resort'];

        try {
            $setup->createHotel($team, ['name' => 'ALAYA Ubud'] + $details);
            $this->fail('the same hotel cannot start a second trial');
        } catch (ValidationException $e) {
            $this->assertSame(TrialHotelGuard::MESSAGE, $e->errors()['name'][0]);
        }

        $this->assertSame('Alaya Resort', $setup->createHotel($team, ['name' => 'Alaya Resort', 'city' => 'Seminyak'] + $details)->name, 'another city is another hotel');

        $this->assertSame('Alaya Resort Ubud', $setup->createHotel($paid->team->fresh()->forceFill(['hotel_quantity' => 2]), ['name' => 'Alaya Resort Ubud', 'city' => 'Canggu'] + $details)->name);
    }

    public function test_the_setup_scan_waits_for_email_verification(): void
    {
        [$owner, $hotel] = $this->trialHotel(verified: false);

        $scan = app(HotelSetupService::class)->finish($hotel, $owner);

        $this->assertSame([ScanStatus::Waiting, 'verification'], [$scan->status, $scan->hold_reason]);
        $this->assertSame(0, AiUsage::count());

        $owner->markEmailAsVerified();
        event(new Verified($owner));

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
    }

    public function test_with_approval_required_new_trials_wait_for_an_admin_and_get_an_email(): void
    {
        config(['citevanta.trial.requires_approval' => true]);
        $this->register('owner@alaya.test')->assertSessionHasNoErrors();
        $owner = User::where('email', 'owner@alaya.test')->sole();
        $owner->markEmailAsVerified();
        $team = $owner->currentTeam;
        $this->assertTrue($team->awaitsTrialApproval());

        $hotel = $this->scannableHotel(prompts: 2, plan: Plan::Trial);
        $hotel->update(['team_id' => $team->id]);
        $scan = app(HotelSetupService::class)->finish($hotel->fresh(), $owner);

        $this->assertSame([ScanStatus::Waiting, 'approval'], [$scan->status, $scan->hold_reason]);
        $this->actingAs($owner)->get(route('settings.team'))->assertSee('We are preparing your account');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.teams'))->assertSee('Approve trial');
        $this->actingAs($admin)->post(route('admin.teams.approve-trial', $team))->assertSessionHas('status');

        Mail::assertQueued(TrialApproved::class, fn ($mail) => $mail->hasTo('owner@alaya.test'));
        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->actingAs($admin)->get(route('admin.teams'))->assertSee('AI cost so far');
    }

    public function test_a_trial_from_a_free_audit_starts_with_the_audits_answers_and_no_new_scan(): void
    {
        config(['citevanta.fake.mention_rate' => 0.5]);
        $this->post(route('audit.store'), ['hotel_name' => 'Alaya Resort Ubud', 'city' => 'Ubud', 'country' => 'Indonesia', 'email' => 'owner@alaya.test', 'locale' => 'en']);
        $audit = Audit::sole();
        $this->assertSame(Audit::COMPLETED, $audit->status);

        $this->get(route('register', ['audit' => $audit->token]));
        $this->post(route('register'), $this->registration('owner@alaya.test'));
        $owner = User::where('email', 'owner@alaya.test')->sole();

        $hotel = app(HotelSetupService::class)->createHotel($owner->currentTeam, ['name' => 'Alaya Resort Ubud', 'city' => 'Ubud', 'country' => 'Indonesia', 'hotel_type' => 'resort']);
        $this->assertSame($audit->id, $hotel->source_audit_id);
        $hotel->prompts()->create(['text' => 'Which resort in Ubud has the best spa for couples?', 'persona' => 'couple', 'language' => 'en', 'is_active' => true]);
        $answersBefore = AiUsage::where('kind', AiCallKind::Answer)->count();

        $scan = app(HotelSetupService::class)->finish($hotel, $owner);

        $this->assertSame([ScanTrigger::AuditImport, ScanStatus::Completed, 10], [$scan->trigger, $scan->status, $scan->responses()->count()]);
        $this->assertSame($answersBefore, AiUsage::where('kind', AiCallKind::Answer)->count(), 'no new answers asked');
        $this->assertSame(0.0, (float) AiUsage::where('scan_run_id', $scan->id)->whereIn('kind', ['answer', 'analysis'])->sum('cost_usd'));
        $this->assertSame(10, $hotel->prompts()->where('source', PromptSource::Audit->value)->where('is_active', false)->count());
        $this->assertSame(1, $hotel->prompts()->where('is_active', true)->count(), 'the trial\'s own prompts are used next time');
        $this->assertSame($audit->scanRun->responses()->whereHas('citations')->count(), $scan->responses()->whereHas('citations')->count());

        $owner->markEmailAsVerified();
        $this->actingAs($owner)->get(route('hotels.scans', $hotel))->assertSee('From your free audit');
    }

    public function test_trials_see_the_top_3_recommendations_and_issues_in_full_and_the_rest_locked(): void
    {
        [$owner, $hotel] = $this->trialHotel();
        foreach ([90, 80, 70, 60, 50] as $priority) {
            Recommendation::factory()->for($hotel)->create(['priority' => $priority, 'title' => "Recommendation {$priority}", 'why' => "Why {$priority}"]);
        }
        foreach (range(1, 4) as $i) {
            AccuracyIssue::factory()->for($hotel)->create(['fact_key' => "Fact {$i}", 'ai_claim' => "Claim {$i}", 'occurrences' => 10 - $i]);
        }

        Livewire::actingAs($owner)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->assertSee('Why 90')->assertSee('Why 70')->assertDontSee('Why 60')
            ->assertSee('Recommendation 50')
            ->assertSee('Upgrade to unlock all 5 recommendations')
            ->call('openContent', Recommendation::where('priority', 50)->sole()->id)->assertForbidden();

        $this->expectException(ContentLimitReached::class);
        try {
            $this->actingAs($owner)->get(route('hotels.accuracy', $hotel))
                ->assertSee('Claim 1')->assertSee('Claim 3')->assertDontSee('Claim 4')
                ->assertSee('Fact 4')->assertSee('Upgrade to unlock all 4 accuracy issues');
        } finally {
            app(ContentService::class)->request(Recommendation::where('priority', 50)->sole(), ContentType::Faq, 'en', $owner);
        }
    }

    public function test_paid_teams_see_everything(): void
    {
        $hotel = $this->scannableHotel(prompts: 1);
        $owner = User::factory()->withTeam($hotel->team)->create();
        foreach ([90, 80, 70, 60] as $priority) {
            Recommendation::factory()->for($hotel)->create(['priority' => $priority, 'why' => "Why {$priority}"]);
        }

        Livewire::actingAs($owner)->test(RecommendationsPage::class, ['hotel' => $hotel])->assertSee('Why 60')->assertDontSee('Upgrade to unlock');
    }

    public function test_members_see_the_lock_without_a_billing_link(): void
    {
        [$owner, $hotel] = $this->trialHotel();
        $member = User::factory()->create();
        $hotel->team->addMember($member, TeamRole::Member);
        $member->switchTeam($hotel->team);
        foreach (range(1, 4) as $i) {
            Recommendation::factory()->for($hotel)->create(['priority' => 100 - $i]);
        }

        Livewire::actingAs($member)->test(RecommendationsPage::class, ['hotel' => $hotel])
            ->assertSee('Upgrade to unlock all 4 recommendations')->assertDontSee(route('settings.billing'));
    }
}
