<?php

namespace Tests\Unit;

use App\Services\Scanning\CostCalculator;
use App\Services\Support\ApiRetry;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CostCalculatorTest extends TestCase
{
    private function calculator(): CostCalculator
    {
        return new CostCalculator(
            modelPrices: [
                'gpt-5' => ['input_per_million' => 1.25, 'output_per_million' => 10.0],
                'gpt-5-mini' => ['input_per_million' => 0.25, 'output_per_million' => 2.0],
            ],
            webSearchPer1000: 10.0,
        );
    }

    public function test_token_and_web_search_costs(): void
    {
        $calc = $this->calculator();

        // 1M in * 0.25 + 0.5M out * 2.00 = 0.25 + 1.00
        $this->assertEqualsWithDelta(1.25, $calc->tokens('gpt-5-mini', 1_000_000, 500_000), 1e-9);
        $this->assertEqualsWithDelta(0.03, $calc->webSearch(3), 1e-9);
    }

    public function test_dated_snapshots_use_the_longest_matching_prefix(): void
    {
        $calc = $this->calculator();

        $this->assertTrue($calc->hasPriceFor('gpt-5-mini-2025-08-07'));
        $this->assertEqualsWithDelta(0.25, $calc->tokens('gpt-5-mini-2025-08-07', 1_000_000, 0), 1e-9);
        $this->assertFalse($calc->hasPriceFor('mystery-model'));
    }

    public function test_retry_skips_client_errors_but_retries_rate_limits_and_network_errors(): void
    {
        $error = fn (int $status) => new ErrorException(['message' => 'x', 'type' => 'y', 'code' => null], new Response($status));

        $this->assertFalse(ApiRetry::isRetryable($error(401)));
        $this->assertFalse(ApiRetry::isRetryable($error(400)));
        $this->assertTrue(ApiRetry::isRetryable($error(429)));
        $this->assertTrue(ApiRetry::isRetryable($error(503)));
        $this->assertTrue(ApiRetry::isRetryable(new RuntimeException('timeout')));
    }
}
