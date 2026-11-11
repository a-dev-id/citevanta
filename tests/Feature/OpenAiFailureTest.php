<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiProvider;
use App\Services\MonitoringMessages;
use App\Services\TestExecutor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OpenAiFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['intelligence.openai_key' => 'mock-api-key']);
        Http::preventStrayRequests();
    }

    private function execute(): TestRun
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->joinLegacyWorkspace($user);

        return app(TestExecutor::class)->execute($user, AiProvider::firstOrFail(), SearchPrompt::firstOrFail(), MarketScenario::firstOrFail(), config('intelligence.model'), (string) Str::uuid());
    }

    public static function failureCases(): array
    {
        return [
            'invalid credential' => [401, 'invalid_api_key', 'invalid_request_error', 'authentication failed'],
            'no API billing' => [429, 'insufficient_quota', 'insufficient_quota', 'billing or usage quota'],
            'no credits' => [429, 'credit_balance_exhausted', 'insufficient_quota', 'API credits'],
            'model permissions' => [404, 'model_not_found', 'invalid_request_error', 'model is unavailable'],
            'project access' => [403, 'permission_denied', 'permission_error', 'denied this project access'],
            'bad web search setting' => [400, 'unsupported_parameter', 'invalid_request_error', 'request configuration'],
            'temporary rate limit' => [429, 'rate_limit_exceeded', 'rate_limit_error', 'rate or credit limit'],
            'provider failure' => [503, 'server_error', 'server_error', 'service error'],
        ];
    }

    #[DataProvider('failureCases')]
    public function test_failures_are_actionable_and_do_not_leak_sensitive_provider_messages(int $status, string $code, string $type, string $expected): void
    {
        $secret = 'sk-do-not-expose-provider-body';
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => $code, 'type' => $type, 'message' => 'Incorrect key: '.$secret]], $status, ['x-request-id' => 'req_safe-support-id'])]);
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'AI search test failed' && $context['error']['code'] === $code && ! str_contains(json_encode($context), $secret));
        $run = $this->execute();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString($expected, $run->response->error_details);
        $this->assertSame($status, $run->response->raw_metadata['http_status']);
        $this->assertSame($code, $run->response->raw_metadata['error']['code']);
        $this->assertSame('req_safe-support-id', $run->response->raw_metadata['request_id']);
        $this->assertStringNotContainsString($secret, $run->response->toJson());
        $this->actingAs(User::where('is_admin', true)->first())->get('/history')->assertOk()->assertSee(MonitoringMessages::publicText($run->response->error_details))->assertDontSee($secret);
        Http::assertSentCount(1);
    }

    public function test_unknown_error_fields_and_processing_exceptions_are_not_exposed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'sk-hidden-code', 'type' => 'sk-hidden-type', 'message' => 'sk-hidden-message']], 400, ['x-request-id' => 'sk-hidden-id'])]);
        $run = $this->execute();
        $this->assertSame('unknown_error', $run->response->raw_metadata['error']['code']);
        $this->assertNull($run->response->raw_metadata['request_id']);
        $this->assertStringNotContainsString('sk-hidden', $run->response->toJson());
        $this->mock(OpenAiProvider::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('sk-hidden-in-exception'));
        $run = $this->execute();
        $this->assertStringNotContainsString('sk-hidden', $run->response->toJson());
        $this->assertSame('processing_error', $run->response->raw_metadata['error']['code']);
    }

    public function test_tls_failures_are_distinguished_from_timeouts_without_disabling_verification(): void
    {
        $cause = new ConnectException('cURL error 60: private diagnostic', new Request('POST', 'https://api.openai.com/v1/responses'));
        Http::fake(fn () => throw new ConnectionException('private diagnostic', 0, $cause));
        $run = $this->execute();
        $this->assertStringContainsString('TLS certificate verification failed', $run->response->error_details);
        $this->assertSame('tls_certificate_error', $run->response->raw_metadata['error']['code']);
        $this->assertStringNotContainsString('private diagnostic', $run->response->toJson());
    }

    public function test_app_key_takes_precedence_over_an_inherited_standard_key(): void
    {
        $names = ['NANDINI_OPENAI_API_KEY', 'OPENAI_API_KEY'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }
        try {
            foreach (['NANDINI_OPENAI_API_KEY' => 'app-specific-test-key', 'OPENAI_API_KEY' => 'inherited-invalid-test-key'] as $name => $value) {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv($name.'='.$value);
            }
            $configuration = require config_path('intelligence.php');
            $this->assertTrue(hash_equals('app-specific-test-key', $configuration['openai_key']), 'The scoped key must take precedence.');
            $_ENV['NANDINI_OPENAI_API_KEY'] = '';
            $_SERVER['NANDINI_OPENAI_API_KEY'] = '';
            putenv('NANDINI_OPENAI_API_KEY=');
            $configuration = require config_path('intelligence.php');
            $this->assertTrue(hash_equals('inherited-invalid-test-key', $configuration['openai_key']), 'An empty scoped key must use the fallback.');
            unset($_ENV['NANDINI_OPENAI_API_KEY'], $_SERVER['NANDINI_OPENAI_API_KEY']);
            putenv('NANDINI_OPENAI_API_KEY');
            $configuration = require config_path('intelligence.php');
            $this->assertTrue(hash_equals('inherited-invalid-test-key', $configuration['openai_key']), 'The standard variable must remain a supported fallback.');
        } finally {
            foreach ($original as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }

    public function test_multiple_output_messages_preserve_citation_offsets_usage_and_raw_metadata(): void
    {
        $data = ['id' => 'resp_multiple', 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'output' => [
            ['type' => 'web_search_call', 'status' => 'completed'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'First answer.', 'annotations' => []]]],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Nandini Jungle Resort', 'annotations' => [['type' => 'url_citation', 'url' => 'https://nandinibali.com/', 'title' => 'Nandini', 'start_index' => 0, 'end_index' => 20]]]]],
        ], 'usage' => ['input_tokens' => 200, 'output_tokens' => 50, 'total_tokens' => 250, 'input_tokens_details' => ['cached_tokens' => 20]]];
        Http::fake(['api.openai.com/*' => Http::response($data)]);
        $run = $this->execute();
        $this->assertSame('completed', $run->status);
        $this->assertSame("First answer.\n\nNandini Jungle Resort", $run->response->text);
        $this->assertSame(15, $run->response->citations->first()->start_index);
        $this->assertSame($data, $run->response->raw_metadata);
        $this->assertSame(250, $run->usage->total_tokens);
        $this->actingAs(User::where('is_admin', true)->first())->get('/tests/'.$run->id)->assertOk()->assertSee('https://nandinibali.com/', false);
    }
}
