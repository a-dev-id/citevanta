<?php

namespace Tests\Feature\Private;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Enums\BillingInterval;
use App\Enums\InvoiceKind;
use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Enums\TeamRole;
use App\Mail\Budget\TeamAiBudgetAlert;
use App\Mail\TeamAiKeyStopped;
use App\Models\AiUsage;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\HttpOpenAiKeyChecker;
use App\Services\Ai\OpenAiKeyScope;
use App\Services\Ai\ScopedOpenAiClient;
use App\Services\Billing\SubscriptionLifecycle;
use App\Services\Budget\AiSpend;
use App\Services\Budget\BillingStop;
use App\Services\Budget\BudgetAlerts;
use App\Services\Engines\AskContext;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineManager;
use App\Services\Engines\EngineResult;
use App\Services\Scanning\ScanLauncher;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

/**
 * Private plan: per-team price and limits, the team's own OpenAI key for
 * every AI call (never our budgets, never our key), its budget and stops.
 */
class PrivatePlanTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-15 04:00:00', 'UTC'));
    }

    /** @return array{0: User, 1: Hotel} */
    private function privateHotel(?string $key = 'sk-team-key-1234567890abcd'): array
    {
        $hotel = $this->scannableHotel(prompts: 2, plan: Plan::Private);
        $team = $hotel->team;
        $team->forceFill(['plan' => Plan::Private, 'private_price_usd' => 300, 'currency' => 'usd', 'billing_interval' => BillingInterval::Monthly,
            'current_period_starts_at' => now()->subDays(10), 'current_period_ends_at' => now()->addDays(20)])->save();
        if ($key !== null) {
            $team->forceFill(['openai_key' => $key, 'openai_key_last4' => substr($key, -4)])->save();
        }
        $owner = User::factory()->withTeam($team)->create();

        return [$owner, $hotel->fresh()];
    }

    /** An engine that remembers which key each call used. */
    private function engine(?\Throwable $throws = null): object
    {
        $engine = new class($throws) implements EngineContract
        {
            /** @var list<?string> */
            public array $keys = [];

            public function __construct(private ?\Throwable $throws) {}

            public function ask(string $prompt, ?AskContext $context = null): EngineResult
            {
                $this->keys[] = app(OpenAiKeyScope::class)->current();

                if ($this->throws) {
                    throw $this->throws;
                }

                return new EngineResult('Try Alaya Resort Ubud.', [], 1000, 200, 1);
            }

            public function name(): string
            {
                return 'openai';
            }

            public function model(): string
            {
                return 'gpt-5-mini';
            }
        };
        app(EngineManager::class)->swap('openai', $engine);

        return $engine;
    }

    public function test_admins_set_a_private_plan_with_its_own_price_and_limits(): void
    {
        $team = Team::factory()->plan(Plan::Trial)->create();
        User::factory()->withTeam($team)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.teams.private-plan', $team))->assertOk()->assertSee('Prompts per hotel');
        $this->actingAs($admin)->put(route('admin.teams.private-plan.update', $team), [
            'price_usd' => 300, 'price_idr' => 4500000, 'currency' => 'idr', 'interval' => 'monthly', 'paid_until' => '2026-12-14',
            'limits' => ['prompts_per_hotel' => 80, 'scans_per_month' => 8, 'hotels' => ''],
        ])->assertRedirect(route('admin.teams'));

        $team->refresh();
        $limits = $team->limits();
        $this->assertSame([Plan::Private, 80, 8, 1, 2, 4, 5, 50], [$team->plan, $limits->promptsPerHotel, $limits->scansPerMonth, $limits->hotels, $limits->runsPerPrompt, $limits->manualScansPerMonth, $limits->languages, $limits->contentGenerationsPerMonth]);
        $this->assertSame(['openai', 'gemini', 'perplexity'], $limits->engines);

        $this->travelTo(Carbon::parse('2026-12-10 04:00:00', 'UTC'));
        app(SubscriptionLifecycle::class)->run();
        $renewal = Invoice::sole();
        $this->assertSame([InvoiceKind::Renewal, 'idr', 4500000], [$renewal->kind, $renewal->currency, $renewal->total]);

        $this->actingAs(User::factory()->withTeam()->create())->get(route('admin.teams.private-plan', $team))->assertForbidden();
    }

    public function test_owners_save_a_key_only_after_it_works_and_it_is_never_shown_again(): void
    {
        [$owner] = $this->privateHotel(key: null);

        $this->actingAs($owner->fresh())->post(route('settings.ai-key.save'), ['key' => 'sk-bad-0000000000000000000'])
            ->assertSessionHas('error', 'The key was not saved: OpenAI answered 401: Incorrect API key provided.');
        $this->assertFalse($owner->currentTeam->fresh()->hasOwnAiKey());

        $this->actingAs($owner->fresh())->post(route('settings.ai-key.save'), ['key' => 'sk-proj-abcdefghijklmnop1234'])
            ->assertSessionHas('status', 'Key works. All AI calls of your team now use it.');

        $team = $owner->currentTeam->fresh();
        $this->assertSame('sk-proj-abcdefghijklmnop1234', $team->openai_key);
        $this->assertStringNotContainsString('sk-proj', (string) DB::table('teams')->where('id', $team->id)->value('openai_key'), 'stored encrypted');
        $this->assertArrayNotHasKey('openai_key', $team->toArray());
        $this->actingAs($owner->fresh())->get(route('settings.ai-key'))->assertOk()->assertSee('sk-...1234')->assertDontSee('sk-proj-abcdefghijklmnop1234');
        $this->actingAs($owner->fresh())->get(route('settings.ai-key.guide'))->assertOk()->assertSee('Turn OFF auto-reload');
    }

    public function test_the_key_check_lists_models_and_returns_openais_exact_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/models' => Http::sequence()
            ->push(['data' => [['id' => 'gpt-5-mini']]])
            ->push(['error' => ['message' => 'Incorrect API key provided: sk-...wxyz.', 'code' => 'invalid_api_key']], 401)]);

        $checker = new HttpOpenAiKeyChecker;
        $this->assertTrue($checker->check('sk-good')['ok']);
        $this->assertSame(['ok' => false, 'error' => 'OpenAI answered 401: Incorrect API key provided: sk-...wxyz.', 'code' => 'invalid_api_key'], $checker->check('sk-bad'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-good'));
    }

    public function test_only_owners_of_private_teams_can_open_the_ai_key_settings(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $member = User::factory()->create();
        $hotel->team->addMember($member, TeamRole::Member);
        $member->switchTeam($hotel->team);

        $this->actingAs($member)->get(route('settings.ai-key'))->assertForbidden();
        $this->actingAs(User::factory()->withTeam()->create())->get(route('settings.ai-key'))->assertForbidden();
        $this->actingAs($owner->fresh())->get(route('settings.ai-key'))->assertOk();
    }

    public function test_every_ai_call_uses_the_teams_key_and_never_counts_against_our_budgets(): void
    {
        $engine = $this->engine();
        [, $hotel] = $this->privateHotel();
        // Our own budgets are completely used up: the private team is not affected.
        AiUsage::create(['bucket' => AiBucket::Paid, 'kind' => AiCallKind::Answer, 'model' => 'gpt-5-mini', 'cost_usd' => 70, 'created_at' => now()]);
        $before = app(AiSpend::class)->total();

        $scan = app(ScanLauncher::class)->queue($hotel, ScanTrigger::Scheduled)->fresh();

        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame(array_fill(0, 4, 'sk-team-key-1234567890abcd'), $engine->keys, '2 prompts x 2 runs, all with the team key');
        $this->assertSame(8, AiUsage::where('scan_run_id', $scan->id)->where('billed_to_team', true)->whereIn('kind', ['answer', 'analysis'])->count());
        $this->assertSame(0, AiUsage::where('scan_run_id', $scan->id)->where('billed_to_team', false)->count());
        $this->assertSame($before, app(AiSpend::class)->total());
    }

    public function test_the_openai_client_switches_to_the_team_key_and_does_not_need_ours(): void
    {
        $oursBuilt = false;
        $client = new ScopedOpenAiClient(app(OpenAiKeyScope::class), function () use (&$oursBuilt) {
            $oursBuilt = true;

            return \OpenAI::client('sk-ours');
        });

        $teamClient = app(OpenAiKeyScope::class)->using('sk-team', fn () => $client->client());
        $this->assertFalse($oursBuilt, 'a team call never builds (or falls back to) our client');
        $this->assertSame($teamClient, app(OpenAiKeyScope::class)->using('sk-team', fn () => $client->client()));
        $this->assertNotSame($teamClient, $client->client());
        $this->assertTrue($oursBuilt);
        $this->assertInstanceOf(ScopedOpenAiClient::class, app(ClientContract::class));
    }

    public function test_a_private_team_without_a_key_makes_no_ai_calls_at_all(): void
    {
        $engine = $this->engine();
        [$owner, $hotel] = $this->privateHotel(key: null);

        $scan = app(ScanLauncher::class)->queue($hotel, ScanTrigger::Scheduled)->fresh();

        $this->assertSame([ScanStatus::PausedBudget, 'team_key'], [$scan->status, $scan->hold_reason]);
        $this->assertSame([], $engine->keys);
        $this->actingAs($owner->fresh())->get(route('settings.team'))->assertSee('Add your OpenAI API key to start scanning');
    }

    public function test_the_teams_monthly_budget_pauses_its_work_and_emails_the_owner_at_50_80_and_100_percent(): void
    {
        $this->engine();
        [$owner, $hotel] = $this->privateHotel();
        $hotel->team->forceFill(['ai_budget_usd' => 1])->save();

        AiUsage::create(['bucket' => AiBucket::Paid, 'billed_to_team' => true, 'kind' => AiCallKind::Answer, 'team_id' => $hotel->team_id, 'model' => 'gpt-5-mini', 'cost_usd' => 0.95, 'created_at' => now()]);
        app(BudgetAlerts::class)->checkTeam($hotel->team->fresh());
        Mail::assertQueued(TeamAiBudgetAlert::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->percent === 80);

        $scan = app(ScanLauncher::class)->queue($hotel, ScanTrigger::Scheduled)->fresh();
        $this->assertSame([ScanStatus::PausedBudget, 'team_budget'], [$scan->status, $scan->hold_reason]);

        $this->actingAs($owner->fresh())->put(route('settings.ai-key.budget'), ['ai_budget_usd' => 50])->assertSessionHas('status');
        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status, 'raising the budget resumes the team\'s paused work');
    }

    public function test_billing_errors_from_the_team_key_pause_only_that_team_and_email_its_owner(): void
    {
        $this->engine(throws: new ErrorException(['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted'], new Response(429)));
        [$owner, $hotel] = $this->privateHotel();

        $scan = app(ScanLauncher::class)->queue($hotel, ScanTrigger::Scheduled)->fresh();

        $this->assertSame([ScanStatus::PausedBudget, 'team_key'], [$scan->status, $scan->hold_reason]);
        $this->assertSame('credit_balance_exhausted', $hotel->team->fresh()->openai_key_stop_code);
        $this->assertFalse(app(BillingStop::class)->isActive(), 'our own AI keeps running for everyone else');
        Mail::assertQueued(TeamAiKeyStopped::class, 1);
        $this->actingAs($owner->fresh())->get(route('settings.team'))->assertSee('Your OpenAI account has no credit left');

        // Other teams are not affected.
        $this->engine();
        $other = app(ScanLauncher::class)->queue($this->scannableHotel(attributes: ['name' => 'Other Resort']), ScanTrigger::Scheduled)->fresh();
        $this->assertSame(ScanStatus::Completed, $other->status);

        // "Test again" with a working key resumes the team.
        $this->actingAs($owner->fresh())->post(route('settings.ai-key.test'))->assertSessionHas('status', 'Key works.');
        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertNull($hotel->team->fresh()->openai_key_stopped_at);
    }

    public function test_an_invalid_team_key_stops_the_team_with_advice(): void
    {
        $this->engine(throws: new ErrorException(['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'], new Response(401)));
        [$owner, $hotel] = $this->privateHotel();

        app(ScanLauncher::class)->queue($hotel, ScanTrigger::Scheduled);

        $this->assertSame('invalid_api_key', $hotel->team->fresh()->openai_key_stop_code);
        $this->actingAs($owner->fresh())->get(route('settings.ai-key'))->assertSee('OpenAI does not accept your API key');
    }
}
