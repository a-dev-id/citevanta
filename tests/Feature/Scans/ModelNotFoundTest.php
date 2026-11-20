<?php

namespace Tests\Feature\Scans;

use App\Enums\ScanTrigger;
use App\Jobs\ScanPromptJob;
use App\Services\Engines\EngineManager;
use App\Services\Scanning\ScanCallRunner;
use App\Services\Scanning\ScanLauncher;
use App\Services\Support\ApiRetry;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use OpenAI\Exceptions\ErrorException;
use Tests\Concerns\BuildsScans;
use Tests\Fakes\StubEngine;
use Tests\TestCase;

class ModelNotFoundTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    private function modelNotFound(string $model): ErrorException
    {
        return new ErrorException([
            'message' => "The model `{$model}` does not exist or you do not have access to it.",
            'type' => 'invalid_request_error',
            'code' => 'model_not_found',
        ], new Response(404));
    }

    public function test_an_unknown_scan_model_is_recorded_clearly_and_not_retried(): void
    {
        config(['citevanta.scan_model' => 'gpt-6.1-terrra', 'citevanta.analysis_model' => 'gpt-6-luna']);
        Queue::fake();
        $run = app(ScanLauncher::class)->queue($this->scannableHotel(), ScanTrigger::Manual);
        $stub = new StubEngine(fn () => throw $this->modelNotFound('gpt-6.1-terrra'));
        app(EngineManager::class)->swap('openai', $stub);

        $this->assertFalse(ApiRetry::isRetryable($this->modelNotFound('x')));

        app()->call([new ScanPromptJob($run->id, $run->hotel->prompts()->first()->id, 1, 'openai'), 'handle']);

        $run->refresh();
        $this->assertCount(1, $stub->prompts, 'asked once, no retry');
        $this->assertSame(1, $run->failed_count);
        $this->assertStringContainsString('Model not found: gpt-6.1-terrra. Check CITEVANTA_SCAN_MODEL', $run->errors[0]);
    }

    public function test_an_unknown_analysis_model_points_to_the_analysis_setting(): void
    {
        config(['citevanta.scan_model' => 'gpt-6.1-terra', 'citevanta.analysis_model' => 'gpt-6-lunaa']);

        $this->assertSame(
            'Model not found: gpt-6-lunaa. Check CITEVANTA_ANALYSIS_MODEL',
            ScanCallRunner::describe($this->modelNotFound('gpt-6-lunaa')),
        );
    }
}
