<?php

namespace Tests\Feature\Scans;

use App\Services\Scanning\CostCalculator;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ModelPricingTest extends TestCase
{
    public function test_production_models_are_priced_from_config(): void
    {
        $calc = CostCalculator::fromConfig();

        // gpt-6.1-terra: $1.00 in, $5.00 out per 1M tokens.
        $this->assertEqualsWithDelta(1.00 + 2.50, $calc->tokens('gpt-6.1-terra', 1_000_000, 500_000), 1e-9);
        // gpt-6-luna: $0.10 in, $0.50 out per 1M tokens (also for dated snapshots).
        $this->assertEqualsWithDelta(0.10 + 0.25, $calc->tokens('gpt-6-luna-2026-10-01', 1_000_000, 500_000), 1e-9);
        $this->assertTrue($calc->hasPriceFor('gpt-5-mini'));
        // Web search: $10 per 1,000 calls.
        $this->assertEqualsWithDelta(0.02, $calc->webSearch(2), 1e-9);
    }

    public function test_an_unknown_model_costs_zero_and_logs_one_warning(): void
    {
        Log::spy();
        $calc = CostCalculator::fromConfig();

        $this->assertSame(0.0, $calc->tokens('gpt-7-nova-preview', 1_000_000, 1_000_000));
        $this->assertSame(0.0, $calc->tokens('gpt-7-nova-preview', 500, 500));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, "no price configured for model 'gpt-7-nova-preview'"))
            ->once();
    }
}
