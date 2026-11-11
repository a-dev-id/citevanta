<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\CostEntry;
use App\Models\CreditEntry;
use App\Models\Improvement;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\OpenAiRequestException;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\CostControl;
use App\Services\ImprovementExplainer;
use App\Services\PaidExecutionGate;
use App\Services\PilotStudy;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaidExecutionGateTest extends TestCase
{
    private User $owner;

    private TestBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();
        $this->app->forgetInstance(PaidExecutionGate::class);
        config(['intelligence.model' => 'gpt-6-luna', 'intelligence.openai_key' => 'mock-only-not-a-real-key']);
        $this->seed();
        $this->owner = User::factory()->create();
        $this->joinLegacyWorkspace($this->owner);
        app(TenantContext::class)->organization()->forceFill(['monthly_limit_micros' => 1500000])->save();
        SpendingSetting::whereKey(1)->update(['monthly_limit_micros' => 2000000]);
        $this->batch = app(PilotStudy::class)->prepare($this->owner);
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_mock_one_use', 'model' => 'gpt-6-luna', 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'No clear recommendation.']]]], 'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens' => 100, 'total_tokens' => 1100]])]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        parent::tearDown();
    }

    private function activateFixture(): void
    {
        $study = app(PilotStudy::class);
        $study->enable($this->batch, $this->owner, 650000, $study->fingerprint($this->batch));
        DB::table('paid_execution_authorizations')->where('id', 1)->update(['status' => 'active', 'approved_by' => $this->owner->id, 'activated_at' => now(), 'expires_at' => now()->addMinutes(5), 'pilot_fingerprint' => $study->fingerprint($this->batch->fresh())]);
    }

    private function reserveFirst(): TestRun
    {
        $item = $this->batch->items()->firstOrFail();
        $submission = (string) Str::uuid();
        $item->update(['status' => 'processing', 'attempts' => 1, 'submission_id' => $submission, 'claimed_at' => now()]);
        $settings = ['model' => 'gpt-6-luna', 'input' => $item->prompt_snapshot['text'], 'instructions' => 'Answer in English. Use web search to answer the query. Clearly distinguish recommendations from businesses only mentioned for context. Cite sources.', 'tools' => [['type' => 'web_search', 'user_location' => ['type' => 'approximate', 'country' => 'AU']]], 'tool_choice' => 'required', 'max_output_tokens' => 2500, 'max_tool_calls' => 1, 'store' => false];

        return app(CostControl::class)->reserve(['user_id' => $this->owner->id, 'ai_provider_id' => $this->batch->ai_provider_id, 'search_prompt_id' => $item->search_prompt_id, 'market_scenario_id' => $item->market_scenario_id, 'test_batch_id' => 1, 'batch_item_id' => $item->id, 'submission_id' => $submission, 'model' => 'gpt-6-luna', 'prompt_version' => 1, 'prompt_text' => $settings['input'], 'market_snapshot' => $item->market_snapshot, 'request_settings' => $settings, 'status' => 'running', 'repetition' => 1, 'executed_at' => now()], $item);
    }

    public function test_default_disabled_and_direct_adapter_calls_never_send_requests(): void
    {
        $this->assertSame('disabled', DB::table('paid_execution_authorizations')->value('status'));
        try {
            app(OpenAiProvider::class)->execute(['model' => 'gpt-6-luna']);
            $this->fail('Direct adapter invocation must be blocked.');
        } catch (OpenAiRequestException $exception) {
            $this->assertSame('paid_execution_denied', $exception->diagnostics['error']['code']);
        }
        Http::assertNothingSent();
    }

    public function test_exactly_one_pilot_attempt_is_consumed_and_cannot_be_replayed(): void
    {
        $this->activateFixture();
        $this->assertSame(1, app(BatchProcessor::class)->process(3));
        Http::assertSentCount(1);
        $grant = DB::table('paid_execution_authorizations')->first();
        $this->assertSame('consumed', $grant->status);
        $this->assertNotNull($grant->consumed_at);
        $run = TestRun::findOrFail($grant->consumed_test_run_id);
        $this->assertSame('completed', $run->status);
        $this->assertSame('paused', $this->batch->fresh()->status);
        $this->assertSame(0, app(BatchProcessor::class)->process(3));
        $this->assertSame(1, CreditEntry::where('status', 'used')->count());
        $this->assertSame('settled', $run->cost->status);
        try {
            app(PaidExecutionGate::class)->forSearch($run, fn () => app(OpenAiProvider::class)->execute($run->request_settings));
            $this->fail('A consumed approval must not be replayed.');
        } catch (OpenAiRequestException) {
            Http::assertSentCount(1);
        }
    }

    public function test_competing_adapter_call_is_rejected_before_first_response_returns(): void
    {
        $this->activateFixture();
        $run = $this->reserveFirst();
        $competing = new PaidExecutionGate;
        $rejected = false;
        Http::fake(function () use ($run, $competing, &$rejected) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('consumed', DB::table('paid_execution_authorizations')->value('status'));
            $this->app->instance(PaidExecutionGate::class, $competing);
            try {
                $competing->forSearch($run, fn () => app(OpenAiProvider::class)->execute($run->request_settings));
            } catch (OpenAiRequestException) {
                $rejected = true;
            }

            return Http::response(['id' => 'resp_mock_competing', 'status' => 'completed', 'output' => []]);
        });
        $gate = app(PaidExecutionGate::class);
        $gate->forSearch($run, fn () => app(OpenAiProvider::class)->execute($run->request_settings));
        $this->assertTrue($rejected);
        Http::assertSentCount(1);
    }

    public function test_timeout_keeps_authorization_consumed_and_never_retries(): void
    {
        $this->activateFixture();
        Http::fake(fn () => throw new ConnectionException('Mock timeout'));
        $this->assertSame(1, app(BatchProcessor::class)->process(3));
        $this->assertSame('consumed', DB::table('paid_execution_authorizations')->value('status'));
        $this->assertSame(1, TestRun::count());
        $this->assertSame('uncertain', CostEntry::firstOrFail()->status);
        $this->assertSame('reserved', CreditEntry::whereNotNull('test_run_id')->firstOrFail()->status);
        $this->assertSame(0, app(BatchProcessor::class)->process(3));
    }

    public static function invalidApprovals(): array
    {
        return ['disabled' => ['status', 'disabled'], 'expired' => ['expires_at', '2000-01-01 00:00:00'], 'unapproved' => ['approved_by', null], 'stale' => ['pilot_fingerprint', 'wrong'], 'already consumed' => ['consumed_at', '2000-01-01 00:00:00']];
    }

    #[DataProvider('invalidApprovals')]
    public function test_invalid_authorizations_are_rejected(string $field, mixed $value): void
    {
        $this->activateFixture();
        DB::table('paid_execution_authorizations')->where('id', 1)->update([$field => $value]);
        app(BatchProcessor::class)->process(1);
        Http::assertNothingSent();
        $this->assertNull(DB::table('paid_execution_authorizations')->value('consumed_test_run_id'));
        $this->assertSame('released', CreditEntry::whereNotNull('test_run_id')->firstOrFail()->status);
        $this->assertSame('released', CostEntry::firstOrFail()->status);
    }

    public function test_quick_test_and_ordinary_campaign_cannot_use_active_pilot_approval(): void
    {
        $this->activateFixture();
        $run = app(TestExecutor::class)->execute($this->owner, AiProvider::where('identifier', 'openai')->firstOrFail(), SearchPrompt::firstOrFail(), MarketScenario::firstOrFail(), 'gpt-6-luna', (string) Str::uuid());
        $this->assertSame('failed', $run->status);
        $this->assertSame('released', $run->cost->status);
        $other = app(BatchManager::class)->preview($this->owner, ['creation_id' => (string) Str::uuid(), 'name' => 'Not authorized', 'prompt_ids' => [SearchPrompt::first()->id], 'market_ids' => [MarketScenario::first()->id], 'repetitions' => 1]);
        app(BatchManager::class)->start($other);
        $this->batch->update(['status' => 'paused']);
        app(BatchProcessor::class)->process(1);
        Http::assertNothingSent();
        $this->assertSame('active', DB::table('paid_execution_authorizations')->value('status'));
    }

    public function test_ai_explanations_cannot_use_a_pilot_approval(): void
    {
        $this->activateFixture();
        $record = Improvement::factory()->create(['evidence' => [['test_id' => 123, 'observation' => 'Mock saved evidence']]]);
        $service = app(ImprovementExplainer::class);
        $preview = $service->preview($record);
        $result = $service->execute($record, $this->owner, (string) Str::uuid(), $preview['fingerprint']);
        $this->assertSame('failed', $result->status);
        $this->assertSame('released', CostEntry::where('improvement_explanation_id', $result->id)->firstOrFail()->status);
        $this->assertSame('released', CreditEntry::where('improvement_explanation_id', $result->id)->firstOrFail()->status);
        $this->assertSame('active', DB::table('paid_execution_authorizations')->value('status'));
        Http::assertNothingSent();
    }

    public static function deniedBoundaries(): array
    {
        return ['other organization' => ['organization'], 'other brand' => ['brand'], 'model' => ['model'], 'payload' => ['payload'], 'global zero' => ['global'], 'organization zero' => ['organization limit'], 'live locked' => ['live lock'], 'unreconciled history' => ['opening'], 'missing reservation' => ['cost'], 'revoked manager' => ['membership'], 'inactive brand' => ['inactive'], 'pilot ceiling' => ['ceiling'], 'credit creator binding' => ['credit binding'], 'request prompt binding' => ['request binding'], 'cost month binding' => ['cost month']];
    }

    #[DataProvider('deniedBoundaries')]
    public function test_scope_payload_and_financial_restrictions_cannot_be_bypassed(string $case): void
    {
        $this->activateFixture();
        $run = $this->reserveFirst();
        $settings = $run->request_settings;
        $context = app(TenantContext::class);
        if ($case === 'organization') {
            $context->organizationId = 2;
        } elseif ($case === 'brand') {
            $context->brandId = 2;
        } elseif ($case === 'model') {
            $settings['model'] = 'gpt-4.1-mini';
        } elseif ($case === 'payload') {
            $settings['input'] = 'A different unapproved prompt';
        } elseif ($case === 'global') {
            SpendingSetting::whereKey(1)->update(['monthly_limit_micros' => 0]);
        } elseif ($case === 'organization limit') {
            $context->organization()->forceFill(['monthly_limit_micros' => 0])->save();
        } elseif ($case === 'live lock') {
            config(['spending.execution_enabled' => false]);
        } elseif ($case === 'opening') {
            DB::table('budget_months')->update(['opening_micros' => null]);
        } elseif ($case === 'cost') {
            $run->cost->update(['status' => 'released']);
        } elseif ($case === 'membership') {
            DB::table('organization_user')->where('user_id', $this->owner->id)->delete();
        } elseif ($case === 'inactive') {
            DB::table('monitored_brands')->where('id', 1)->update(['active' => false]);
        } elseif ($case === 'credit binding') {
            CreditEntry::where('test_run_id', $run->id)->update(['user_id' => User::factory()->create()->id]);
        } elseif ($case === 'request binding') {
            $run->update(['search_prompt_id' => SearchPrompt::first()->id]);
        } elseif ($case === 'cost month') {
            $id = DB::table('budget_months')->insertGetId(['month' => '2000-01', 'opening_micros' => 0, 'opening_through_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $run->cost->update(['budget_month_id' => $id]);
        } elseif ($case === 'ceiling') {
            $this->batch->update(['pilot_budget_micros' => 660000]);
        }
        try {
            app(PaidExecutionGate::class)->forSearch($run, fn () => app(OpenAiProvider::class)->execute($settings));
            $this->fail('Unapproved scope or financial state must be rejected.');
        } catch (\Throwable $exception) {
            $this->assertTrue($exception instanceof OpenAiRequestException || $exception instanceof ValidationException);
        }
        Http::assertNothingSent();
        $this->assertSame('active', DB::table('paid_execution_authorizations')->value('status'));
    }

    public function test_gate_consumption_does_not_change_historical_records(): void
    {
        $history = TestRun::create(['user_id' => $this->owner->id, 'ai_provider_id' => $this->batch->ai_provider_id, 'search_prompt_id' => SearchPrompt::first()->id, 'market_scenario_id' => MarketScenario::first()->id, 'submission_id' => (string) Str::uuid(), 'model' => 'gpt-4.1-mini', 'prompt_text' => 'Historical fixture', 'prompt_version' => 1, 'market_snapshot' => [], 'request_settings' => [], 'status' => 'completed', 'executed_at' => now()->subMonth()]);
        $response = $history->response()->create(['text' => 'Historical response fixture', 'status' => 'completed']);
        $before = [$history->fresh()->getAttributes(), $response->fresh()->getAttributes()];
        $this->activateFixture();
        app(BatchProcessor::class)->process(1);
        $this->assertSame($before, [$history->fresh()->getAttributes(), $response->fresh()->getAttributes()]);
        $this->assertDatabaseMissing('cost_entries', ['test_run_id' => $history->id]);
        $this->assertDatabaseMissing('credit_entries', ['test_run_id' => $history->id]);
    }

    public function test_two_independent_workers_cannot_both_consume_the_authorization(): void
    {
        $this->activateFixture();
        $run = $this->reserveFirst();
        $path = storage_path('framework/paid-gate-concurrency-'.Str::uuid().'.sqlite');
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($path));
        $start = (string) (microtime(true) + 2);
        $workers = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('tests/Fixtures/paid-gate-worker.php'), $path, (string) $run->id, $start], base_path(), ['PAID_GATE_CONCURRENCY_TEST' => '1']);
                $process->setTimeout(20);
                $process->start();
                $workers[] = $process;
            }
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'outcome');
            sort($outcomes);
            $this->assertSame(['allowed', 'blocked'], $outcomes);
            $this->assertSame(1, array_sum(array_column($results, 'mocked_requests')));
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            @unlink($path);
        }
    }

    public function test_nested_transaction_cannot_roll_back_an_external_authorization(): void
    {
        $this->activateFixture();
        $run = $this->reserveFirst();
        try {
            DB::transaction(fn () => app(PaidExecutionGate::class)->forSearch($run, fn () => app(OpenAiProvider::class)->execute($run->request_settings)));
            $this->fail('Authorization consumption must commit before HTTP.');
        } catch (OpenAiRequestException) {
            Http::assertNothingSent();
            $this->assertSame('active', DB::table('paid_execution_authorizations')->value('status'));
        }
    }
}
