<?php

namespace Tests\Feature;

use App\Models\CostEntry;
use App\Models\SpendingSetting;
use App\Models\User;
use App\Services\BatchManager;
use App\Services\BatchProcessor;
use App\Services\PilotQuality;
use App\Services\PilotStudy;
use App\Services\RequestPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LunaPricingTest extends TestCase
{
    use RefreshDatabase;

    private function response(array $details = []): array
    {
        return ['id' => 'resp_luna_fixture', 'model' => 'gpt-6-luna', 'status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'No clear hotel recommendation.', 'annotations' => []]]]], 'usage' => ['input_tokens' => 1000, 'input_tokens_details' => array_replace(['cached_tokens' => 100, 'cache_write_tokens' => 500, 'cache_write_tokens_details' => ['ttl_30m_tokens' => 300, 'ttl_12h_tokens' => 200]], $details), 'output_tokens' => 100, 'total_tokens' => 1100]];
    }

    public function test_luna_variable_search_and_cache_writes_are_priced_once(): void
    {
        $pricing = app(RequestPricing::class);
        $snapshot = $pricing->snapshot('gpt-6-luna');
        $this->assertSame(10179, $pricing->usage($this->response(), $snapshot));
        $this->assertFalse($snapshot['rates']['search_input_is_fixed']);
        $this->assertSame(8000, $snapshot['rates']['search_input_tokens']);
    }

    public function test_luna_unknown_or_inconsistent_write_usage_fails_closed(): void
    {
        $pricing = app(RequestPricing::class);
        $snapshot = $pricing->snapshot('gpt-6-luna');
        foreach ([['cache_write_tokens' => null], ['cache_write_tokens' => -1], ['cached_tokens' => 900], ['cache_write_tokens' => 0], ['cache_write_tokens_details' => null], ['cache_write_tokens_details' => ['ttl_30m_tokens' => 500, 'ttl_12h_tokens' => 1]]] as $details) {
            $this->assertNull($pricing->usage($this->response($details), $snapshot));
        }
    }

    public function test_luna_zero_writes_and_reasoning_output_need_no_synthetic_token_additions(): void
    {
        $raw = $this->response(['cached_tokens' => 0, 'cache_write_tokens' => 0, 'cache_write_tokens_details' => null]);
        $raw['usage']['output_tokens_details'] = ['reasoning_tokens' => 75];
        $this->assertSame(10150, app(RequestPricing::class)->usage($raw, app(RequestPricing::class)->snapshot('gpt-6-luna')));
    }

    public function test_legacy_mini_usage_and_quote_remain_compatible(): void
    {
        $pricing = app(RequestPricing::class);
        $raw = $this->response(['cached_tokens' => 0]);
        $raw['model'] = 'gpt-4.1-mini-2025-04-14';
        $raw['usage'] = ['input_tokens' => 8528, 'output_tokens' => 512];
        $this->assertSame(14232, $pricing->usage($raw, $pricing->snapshot('gpt-4.1-mini')));
        $this->assertSame(81500, $pricing->quote('Best luxury resorts in Ubud Bali', 'gpt-4.1-mini', 25)['reserve']);
    }

    public function test_luna_quote_reserves_for_cache_writes_and_search_calls(): void
    {
        $quote = app(RequestPricing::class)->quote('Best luxury resorts in Ubud Bali', 'gpt-6-luna', 25);
        $this->assertSame(54063, $quote['reserve']);
        $this->assertGreaterThan(10000, $quote['estimate']);
        $this->assertGreaterThan($quote['estimate'], $quote['reserve']);
    }

    public function test_luna_missing_cache_write_price_blocks_quotes(): void
    {
        config(['spending.pricing.models.gpt-6-luna.cache_write_12h_micros_per_million' => null]);
        $this->expectException(ValidationException::class);
        app(RequestPricing::class)->snapshot('gpt-6-luna');
    }

    public function test_one_mocked_luna_pilot_result_is_saved_and_costs_reconciled(): void
    {
        config(['intelligence.model' => 'gpt-6-luna', 'intelligence.openai_key' => 'mock-not-a-key']);
        $this->seed();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($admin);
        SpendingSetting::whereKey(1)->update(['monthly_limit_micros' => 2000000, 'batch_request_limit' => 20, 'safety_percent' => 25]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->response())]);
        $study = app(PilotStudy::class);
        $batch = $study->prepare($admin);
        $this->assertSame(648756, $batch->reserved_estimate_micros);
        $study->enable($batch, $admin, 650000, $study->fingerprint($batch));
        $this->assertSame(1, app(BatchProcessor::class)->process(3));
        Http::assertSentCount(1);
        $run = $batch->runs()->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame('gpt-6-luna', $run->model);
        $this->assertSame(1100, $run->usage->total_tokens);
        $cost = CostEntry::where('test_run_id', $run->id)->firstOrFail();
        $this->assertSame(10179, $cost->usage_cost_micros);
        $this->assertSame(12724, $cost->committed_micros);
        $this->assertSame('settled', $cost->status);
        $this->assertSame(11, $batch->items()->where('status', 'pending')->count());
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->pilot_enabled_at);
        $this->assertSame(0, app(BatchProcessor::class)->process(3));
        try {
            app(BatchManager::class)->start($batch->fresh());
            $this->fail('Generic resume must not bypass first-result review.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('first pilot result', $exception->getMessage());
        }
        $quality = app(PilotQuality::class);
        $inspection = $quality->inspect($run);
        $quality->review($run, $admin, 'approved', null, $inspection['fingerprint'], true);
        $study->enable($batch->fresh(), $admin, 650000, $study->fingerprint($batch->fresh()));
        $this->assertSame('running', $batch->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_failed_first_attempt_cannot_be_resumed_into_remaining_pilot(): void
    {
        config(['intelligence.model' => 'gpt-6-luna', 'intelligence.openai_key' => 'mock-not-a-key']);
        $this->seed();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->joinLegacyWorkspace($admin);
        SpendingSetting::whereKey(1)->update(['monthly_limit_micros' => 2000000]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota']], 429)]);
        $study = app(PilotStudy::class);
        $batch = $study->prepare($admin);
        $study->enable($batch, $admin, 650000, $study->fingerprint($batch));
        $this->assertSame(1, app(BatchProcessor::class)->process(3));
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame(0, app(BatchProcessor::class)->process(3));
        $this->assertSame(11, $batch->items()->where('status', 'pending')->count());
        Http::assertSentCount(1);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('first pilot result');
        $study->enable($batch->fresh(), $admin, 650000, $study->fingerprint($batch->fresh()));
    }
}
