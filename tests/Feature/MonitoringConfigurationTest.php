<?php

namespace Tests\Feature;

use App\Models\CostEntry;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\PilotStudy;
use App\Services\RequestPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonitoringConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->administrator = User::factory()->create();
        $this->joinLegacyWorkspace($this->administrator);
        config(['intelligence.openai_key' => 'mock-monitoring-key', 'intelligence.daily_limit' => 100]);
        Http::preventStrayRequests();
    }

    private function preview(): TestBatch
    {
        return app(BatchManager::class)->preview($this->administrator, ['creation_id' => (string) Str::uuid(), 'name' => 'Workspace monitoring', 'prompt_ids' => [SearchPrompt::firstOrFail()->id], 'market_ids' => [MarketScenario::firstOrFail()->id], 'repetitions' => 1]);
    }

    public function test_currency_display_rounds_without_changing_internal_precision(): void
    {
        $this->assertSame('$0.00 USD', RequestPricing::dollars(1));
        $this->assertSame('$0.01 USD', RequestPricing::dollars(10179));
        $this->assertSame('$1,234.57 USD', RequestPricing::dollars(1234567890));
        $this->assertSame(10179, RequestPricing::micros('0.010179'));
        $this->assertSame(12724, app(RequestPricing::class)->withSafety(10179, 25));
    }

    public function test_luna_is_shown_consistently_on_new_execution_pages(): void
    {
        config(['intelligence.model' => 'gpt-6-luna']);
        $this->actingAs($this->administrator);
        foreach (['/tests', '/batches/create', '/settings', '/pilot'] as $url) {
            $this->get($url)->assertOk()->assertSee('gpt-6-luna')->assertDontSee('gpt-4.1-mini');
        }
        Http::assertNothingSent();
    }

    public function test_campaign_and_console_worker_use_the_configured_model_and_its_prices(): void
    {
        config(['intelligence.model' => 'gpt-6-luna']);
        $batch = $this->preview();
        $this->assertSame('gpt-6-luna', $batch->model);
        $this->assertSame(app(RequestPricing::class)->snapshot('gpt-6-luna'), $batch->pricing_snapshot);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_monitoring_mock', 'model' => 'gpt-6-luna', 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'No verified recommendation.', 'annotations' => []]]]], 'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens' => 100, 'total_tokens' => 1100]])]);
        app(BatchManager::class)->start($batch);
        $this->artisan('intelligence:batches-process', ['--limit' => 1])->assertSuccessful();
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-6-luna' && $request['tools'][0]['type'] === 'web_search');
        Http::assertSentCount(1);
        $this->assertSame('gpt-6-luna', TestRun::firstOrFail()->model);
        $this->assertSame(10150, CostEntry::firstOrFail()->usage_cost_micros);
    }

    public function test_model_changes_invalidate_campaigns_before_start_and_during_processing(): void
    {
        $batch = $this->preview();
        app(BatchManager::class)->start($batch);
        config(['intelligence.model' => 'gpt-6-luna']);
        try {
            app(BatchManager::class)->start($batch);
            $this->fail('A stale model must block start.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('model changed', $exception->getMessage());
        }
        $this->assertSame(0, app(BatchProcessor::class)->process(1));
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame('gpt-4.1-mini', $batch->fresh()->model);
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_refreshing_an_unexecuted_pilot_updates_model_and_invalidates_approval(): void
    {
        $study = app(PilotStudy::class);
        $batch = $study->prepare($this->administrator);
        $items = $batch->items()->get()->toArray();
        $originalFingerprint = $study->fingerprint($batch);
        config(['intelligence.model' => 'gpt-6-luna']);
        $this->assertFalse($study->preview($batch)['checks']['Provider configuration']['passed']);
        $refreshed = $study->prepare($this->administrator);
        $this->assertSame($batch->id, $refreshed->id);
        $this->assertSame('gpt-6-luna', $refreshed->model);
        $this->assertSame('gpt-6-luna', $refreshed->pricing_snapshot['model']);
        $this->assertNotSame($originalFingerprint, $study->fingerprint($refreshed));
        $this->assertNull($refreshed->pilot_enabled_at);
        $this->assertSame($items, $refreshed->items()->get()->toArray());
        $this->assertSame(0, TestRun::count());
        Http::assertNothingSent();
    }

    public function test_campaigns_use_workspace_markets_and_industry_neutral_prompts(): void
    {
        $market = MarketScenario::create(['name' => 'Canada / French', 'country' => 'CA', 'language' => 'French', 'search_settings' => ['type' => 'approximate', 'country' => 'CA']]);
        $prompt = SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Software', 'text' => 'Recommended accounting software for small businesses', 'version' => 1, 'active' => true]);
        $data = ['creation_id' => (string) Str::uuid(), 'name' => 'Software visibility', 'prompt_ids' => [$prompt->id], 'market_ids' => [$market->id], 'repetitions' => 1];
        $this->actingAs($this->administrator)->get('/batches/create')->assertOk()->assertSee('Canada / French');
        $this->withSession(['batch_creations' => [$data['creation_id']]])->post('/batches/preview', $data)->assertSessionHasNoErrors()->assertRedirect();
        $batch = TestBatch::where('creation_id', $data['creation_id'])->firstOrFail();
        $this->assertSame($prompt->text, $batch->items()->firstOrFail()->prompt_snapshot['text']);
        $this->assertSame('CA', $batch->items()->firstOrFail()->market_snapshot['country']);
        Http::assertNothingSent();
    }
}
