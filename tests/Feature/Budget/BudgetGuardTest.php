<?php

namespace Tests\Feature\Budget;

use App\Enums\AiBucket;
use App\Enums\AiCallKind;
use App\Enums\Plan;
use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Mail\Budget\BudgetAlert;
use App\Mail\Budget\OpenAiBillingStopped;
use App\Mail\Budget\RunawayScanStopped;
use App\Models\AiUsage;
use App\Models\Hotel;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\BillingStop;
use App\Services\Budget\BudgetExceeded;
use App\Services\Budget\BudgetGuard;
use App\Services\Budget\BudgetPanel;
use App\Services\Engines\AskContext;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineManager;
use App\Services\Engines\EngineResult;
use App\Services\Engines\OpenAiEngine;
use App\Services\Scanning\ManualScanGate;
use App\Services\Scanning\ScanLauncher;
use App\Services\Support\ApiRetry;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use Tests\Concerns\BuildsScans;
use Tests\TestCase;

/**
 * BudgetGuard: real cost recording, buckets, total, priority, per-team cap,
 * paused_budget and resume, OpenAI billing stop, runaway guard and alerts.
 */
class BudgetGuardTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-15 04:00:00', 'UTC'));
        config(['citevanta.budget.total' => 70, 'citevanta.budget.buckets' => ['audits' => 10, 'trials' => 10, 'admin' => 10]]);
    }

    /** Spend recorded this month in a bucket. */
    private function spent(AiBucket $bucket, float $usd): void
    {
        AiUsage::create(['bucket' => $bucket, 'kind' => AiCallKind::Answer, 'model' => 'gpt-5-mini', 'cost_usd' => $usd, 'created_at' => now()]);
    }

    private function engine(int $searches = 1, ?\Throwable $throws = null): EngineContract
    {
        $engine = new class($searches, $throws) implements EngineContract
        {
            public int $calls = 0;

            public function __construct(private int $searches, private ?\Throwable $throws) {}

            public function ask(string $prompt, ?AskContext $context = null): EngineResult
            {
                $this->calls++;

                if ($this->throws) {
                    throw $this->throws;
                }

                return new EngineResult('Try Alaya Resort Ubud or Kayon Jungle Resort.', [], 2000, 500, $this->searches);
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

    private function scan(Hotel $hotel, ScanTrigger $trigger = ScanTrigger::Scheduled): ScanRun
    {
        return app(ScanLauncher::class)->queue($hotel, $trigger)->fresh();
    }

    public function test_the_real_cost_of_every_call_is_recorded_including_several_web_searches(): void
    {
        $this->engine(searches: 3);
        $hotel = $this->scannableHotel(prompts: 1);
        $scan = $this->scan($hotel);

        $answer = AiUsage::where('scan_run_id', $scan->id)->where('kind', AiCallKind::Answer)->first();
        // gpt-5-mini: 2000 in x $0.25/M + 500 out x $2/M + 3 searches x $0.01
        $this->assertSame([AiBucket::Paid, 3, 2000, 500], [$answer->bucket, $answer->web_searches, $answer->tokens_in, $answer->tokens_out]);
        $this->assertEqualsWithDelta(0.0005 + 0.001 + 0.03, $answer->cost_usd, 1e-6);
        $this->assertSame(4, AiUsage::where('scan_run_id', $scan->id)->whereIn('kind', ['answer', 'analysis'])->count(), 'Starter: 1 prompt x 2 runs, each an answer and an analysis');

        // Counted from the output items, never assumed.
        $parsed = OpenAiEngine::fromResponseArray(['output' => [['type' => 'web_search_call'], ['type' => 'web_search_call'], ['type' => 'message', 'content' => []]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
        $this->assertSame(2, $parsed->webSearchCalls);
    }

    public function test_a_full_bucket_pauses_its_work_without_failing_it_and_other_buckets_continue(): void
    {
        $engine = $this->engine();
        $this->spent(AiBucket::Trials, 9.99);
        $trialHotel = $this->scannableHotel(prompts: 2, plan: Plan::Trial);
        User::factory()->withTeam($trialHotel->team)->create();

        $paused = $this->scan($trialHotel);

        $this->assertSame(ScanStatus::PausedBudget, $paused->status);
        $this->assertSame('bucket', $paused->hold_reason);
        $this->assertSame(0, $engine->calls);

        $paid = $this->scan($this->scannableHotel(prompts: 1, attributes: ['name' => 'Paid Resort']));
        $this->assertSame(ScanStatus::Completed, $paid->status);
    }

    public function test_each_bucket_and_the_total_are_enforced(): void
    {
        $guard = app(BudgetGuard::class);

        foreach ([AiBucket::Audits, AiBucket::Trials, AiBucket::Admin] as $bucket) {
            $this->spent($bucket, 9.99);

            try {
                $guard->authorize(new AiCallContext($bucket), AiCallKind::Answer);
                $this->fail("{$bucket->value} should be full");
            } catch (BudgetExceeded $e) {
                $this->assertSame(BudgetExceeded::BUCKET, $e->reason);
            }
        }

        $guard->authorize(new AiCallContext(AiBucket::Paid, isPaidScheduled: true), AiCallKind::Answer);

        $this->spent(AiBucket::Paid, 40);
        $this->expectExceptionObject(new BudgetExceeded(BudgetExceeded::TOTAL, 'The monthly AI budget is used up.'));
        $guard->authorize(new AiCallContext(AiBucket::Paid, isPaidScheduled: true), AiCallKind::Answer);
    }

    public function test_above_90_percent_only_paid_scheduled_scans_continue(): void
    {
        $this->spent(AiBucket::Paid, 63.5);
        $guard = app(BudgetGuard::class);

        $guard->authorize(new AiCallContext(AiBucket::Paid, isPaidScheduled: true), AiCallKind::Answer);

        foreach ([new AiCallContext(AiBucket::Paid), new AiCallContext(AiBucket::Audits), new AiCallContext(AiBucket::Trials), new AiCallContext(AiBucket::Admin)] as $context) {
            try {
                $guard->authorize($context, AiCallKind::Content);
                $this->fail("{$context->bucket->value} should pause");
            } catch (BudgetExceeded $e) {
                $this->assertSame(BudgetExceeded::PRIORITY, $e->reason);
            }
        }

        $scheduled = ScanRun::factory()->for($this->scannableHotel())->create(['trigger' => ScanTrigger::Scheduled]);
        $manual = ScanRun::factory()->for($scheduled->hotel)->create(['trigger' => ScanTrigger::Manual]);
        $this->assertTrue(AiCallContext::forScan($scheduled)->isPaidScheduled);
        $this->assertFalse(AiCallContext::forScan($manual)->isPaidScheduled);
    }

    public function test_a_paid_team_over_its_cost_cap_gets_no_manual_scans_or_content_but_scheduled_scans_run(): void
    {
        $hotel = $this->scannableHotel(prompts: 2);
        $owner = User::factory()->withTeam($hotel->team)->create();
        // Starter $49 x 30% = $14.70
        AiUsage::create(['bucket' => AiBucket::Paid, 'kind' => AiCallKind::Answer, 'team_id' => $hotel->team_id, 'model' => 'gpt-5-mini', 'cost_usd' => 14.70, 'created_at' => now()]);

        $check = app(ManualScanGate::class)->check($hotel, $owner);
        $this->assertFalse($check->allowed);
        $this->assertStringContainsString('allowance for extra scans and content', $check->reason);

        $this->engine();
        $this->assertSame(ScanStatus::Completed, $this->scan($hotel)->status, 'scheduled scans still run');
    }

    public function test_paused_work_resumes_from_admin_system_once_there_is_budget(): void
    {
        $engine = $this->engine();
        $this->spent(AiBucket::Trials, 9.99);
        $hotel = $this->scannableHotel(prompts: 2, plan: Plan::Trial);
        User::factory()->withTeam($hotel->team)->create();
        $scan = $this->scan($hotel);
        $this->assertSame(ScanStatus::PausedBudget, $scan->status);

        config(['citevanta.budget.buckets.trials' => 20]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.system'))->assertOk()->assertSee('AI budget')->assertSee('1 paused job');
        $this->actingAs($admin)->post(route('admin.system.resume-paused'))->assertSessionHas('status', '1 paused job resumed.');

        $this->assertSame(ScanStatus::Completed, $scan->fresh()->status);
        $this->assertSame(2, $engine->calls);
    }

    public function test_openai_billing_errors_stop_everything_are_never_retried_and_alert_once(): void
    {
        $error = new ErrorException(['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted'], new Response(429));
        $this->assertFalse(ApiRetry::isRetryable($error));
        $this->assertTrue(ApiRetry::isRetryable(new RateLimitException(new Response(429, [], json_encode(['error' => ['code' => 'rate_limit_exceeded']])))), 'normal 429s keep their retries');
        $this->assertFalse(ApiRetry::isRetryable(new RateLimitException(new Response(429, [], json_encode(['error' => ['code' => 'organization_spend_limit_exceeded']])))));

        User::factory()->admin()->create();
        $engine = $this->engine(throws: $error);
        $waiting = ScanRun::factory()->for($this->scannableHotel(attributes: ['name' => 'Queued Resort']))->create(['status' => ScanStatus::Queued, 'started_at' => null, 'finished_at' => null]);

        $scan = $this->scan($this->scannableHotel(prompts: 2));

        $this->assertSame(1, $engine->calls, 'the second call is not even tried');
        $this->assertSame(ScanStatus::PausedBudget, $scan->status);
        $this->assertSame(ScanStatus::PausedBudget, $waiting->fresh()->status, 'all queued AI work pauses');
        $this->assertTrue(app(BillingStop::class)->isActive());
        Mail::assertQueued(OpenAiBillingStopped::class, 1);

        $this->scan($this->scannableHotel(attributes: ['name' => 'Later Resort']));
        $this->assertSame(1, $engine->calls);
        Mail::assertQueued(OpenAiBillingStopped::class, 1);

        $this->actingAs(User::query()->where('is_admin', true)->first())->get(route('admin.system'))->assertSee('OpenAI credit or spend limit reached');
    }

    public function test_a_scan_making_more_calls_than_its_plan_allows_is_stopped_and_admins_are_alerted(): void
    {
        User::factory()->admin()->create();
        $hotel = $this->scannableHotel(prompts: 1);
        $scan = ScanRun::factory()->for($hotel)->create(['status' => ScanStatus::Running, 'prompt_ids' => [1], 'runs_per_prompt' => 1, 'engine' => 'openai', 'finished_at' => null]);
        $max = app(BudgetGuard::class)->maxCalls($scan);
        $this->assertSame(1 * 2 + 40, $max);

        foreach (range(1, $max) as $i) {
            AiUsage::create(['bucket' => AiBucket::Paid, 'kind' => AiCallKind::Insight, 'scan_run_id' => $scan->id, 'model' => 'gpt-5-mini', 'cost_usd' => 0, 'created_at' => now()]);
        }

        try {
            app(BudgetGuard::class)->authorize(AiCallContext::forScan($scan), AiCallKind::Insight);
            $this->fail('runaway scan must stop');
        } catch (BudgetExceeded $e) {
            $this->assertSame(BudgetExceeded::RUNAWAY, $e->reason);
        }

        $this->assertSame(ScanStatus::Failed, $scan->fresh()->status);
        Mail::assertQueued(RunawayScanStopped::class, 1);
    }

    public function test_admins_are_alerted_once_at_50_80_and_100_percent(): void
    {
        User::factory()->admin()->create();
        $guard = app(BudgetGuard::class);

        $guard->record(new AiCallContext(AiBucket::Audits), AiCallKind::Answer, 'gpt-5-mini', 0, 0, 550);   // $5.50 = 55%
        $guard->record(new AiCallContext(AiBucket::Audits), AiCallKind::Answer, 'gpt-5-mini', 0, 0, 10);    // 56%: nothing new
        Mail::assertQueued(BudgetAlert::class, fn ($mail) => $mail->bucket === 'Free audits' && $mail->percent === 50);
        Mail::assertQueued(BudgetAlert::class, 1);

        $guard->record(new AiCallContext(AiBucket::Audits), AiCallKind::Answer, 'gpt-5-mini', 0, 0, 500);   // 106%
        Mail::assertQueued(BudgetAlert::class, fn ($mail) => $mail->percent === 80);
        Mail::assertQueued(BudgetAlert::class, fn ($mail) => $mail->percent === 100);
        Mail::assertQueued(BudgetAlert::class, 3);

        $this->travelTo(Carbon::parse('2026-12-02 04:00:00', 'UTC'));
        $this->assertSame(0.0, app(BudgetPanel::class)->data()['rows'][0]['spent'], 'buckets reset on the 1st');
    }

    public function test_admin_scans_show_their_estimated_cost_before_starting(): void
    {
        $hotel = $this->scannableHotel(prompts: 3);
        $admin = User::factory()->admin()->withTeam($hotel->team)->create();

        $this->actingAs($admin)->get(route('hotels.show', $hotel))->assertSee('estimated cost $', false)->assertSee('admin AI budget');
        $this->artisan('scan:test', ['hotel' => 'Probe Hotel', 'city' => 'Ubud', '--country' => 'Indonesia', '--runs' => 1])
            ->expectsOutputToContain('Estimated cost: $')
            ->assertSuccessful();
        $this->assertSame([AiBucket::Admin->value], AiUsage::distinct()->pluck('bucket')->map(fn ($b) => $b->value)->all());
    }
}
