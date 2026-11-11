<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\HotelRecognition;
use App\Services\TestExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['intelligence.openai_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->joinLegacyWorkspace($user);

        return $user;
    }

    private function payload(string $text = '1. Nandini Jungle by Hanging Gardens is recommended.'): array
    {
        return ['id' => 'resp_test', 'status' => 'completed', 'model' => 'gpt-4.1-mini', 'output' => [['type' => 'web_search_call', 'status' => 'completed'], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => [['type' => 'url_citation', 'url' => 'https://nandinibali.com/stay', 'title' => 'Nandini', 'start_index' => 3, 'end_index' => 38], ['type' => 'url_citation', 'url' => 'javascript:alert(1)', 'title' => 'Unsafe'], ['type' => 'url_citation', 'url' => 'https://nandinibali.com.attacker.example', 'title' => 'Other']]]]]], 'usage' => ['input_tokens' => 110, 'output_tokens' => 80, 'total_tokens' => 190]];
    }

    private function execute(?string $submission = null): TestRun
    {
        return app(TestExecutor::class)->execute($this->admin(), AiProvider::first(), SearchPrompt::first(), MarketScenario::first(), config('intelligence.model'), $submission ?? (string) Str::uuid());
    }

    public function test_routes_require_an_administrator_and_login_regenerates_session(): void
    {
        foreach (['/dashboard', '/tests', '/prompts', '/history', '/settings'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $user = $this->admin();
        $user->password = 'SecurePassword123';
        $user->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword123'])->assertRedirect('/settings');
        $this->assertAuthenticatedAs($user);
        foreach (['/dashboard', '/tests', '/prompts', '/prompts/create', '/history', '/settings'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'absent@example.com', 'password' => 'wrong']);
        } $this->post('/login', ['email' => 'absent@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_empty_dashboard_and_prompt_versioning(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('No tests yet');
        $this->post('/prompts', ['category' => 'Travel', 'text' => 'Luxury stays', 'active' => 1])->assertRedirect('/prompts');
        $prompt = SearchPrompt::where('text', 'Luxury stays')->firstOrFail();
        $this->put('/prompts/'.$prompt->id, ['category' => 'Travel', 'text' => 'Luxury jungle stays', 'active' => 0])->assertRedirect('/prompts');
        $this->assertSame(2, $prompt->fresh()->version);
        $this->assertFalse($prompt->fresh()->active);
        $this->assertDatabaseCount('test_runs', 0);
        $this->post('/prompts', ['category' => '', 'text' => '', 'active' => 1])->assertSessionHasErrors(['category', 'text']);
    }

    public function test_success_persists_response_sources_usage_and_snapshot_without_duplicate_request(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->payload())]);
        $id = (string) Str::uuid();
        $run = $this->execute($id);
        $this->assertSame('completed', $run->status);
        $this->assertSame('resp_test', $run->response->api_response_id);
        $this->assertSame(190, $run->usage->total_tokens);
        $this->assertNull($run->usage->estimated_cost);
        $this->assertCount(2, $run->response->citations);
        $this->assertSame(1, $run->response->citations->where('is_official', true)->count());
        $this->assertTrue($run->response->mentions->first()->is_recommendation);
        $this->execute($id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses' && $request['tools'][0]['type'] === 'web_search' && $request['tool_choice'] === 'required');
        $run->prompt->update(['text' => 'Changed prompt', 'version' => 2]);
        $this->assertSame('Best luxury jungle resorts in Ubud Bali', $run->fresh()->prompt_text);
        $this->actingAs($this->admin())->get('/tests/'.$run->id)->assertOk()->assertSee('Nandini Jungle by Hanging Gardens');
        $this->get('/dashboard')->assertOk()->assertSee('100%');
    }

    public function test_submission_runs_through_authenticated_form_and_rejects_unknown_tokens(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->payload())]);
        $this->actingAs($this->admin())->get('/tests')->assertOk();
        $submission = session('test_submissions')[0];
        $data = ['provider_id' => AiProvider::first()->id, 'prompt_id' => SearchPrompt::first()->id, 'market_id' => MarketScenario::first()->id, 'model' => config('intelligence.model'), 'submission_id' => $submission];
        $this->post('/tests', $data)->assertRedirect('/tests/1');
        $this->post('/tests', $data)->assertRedirect('/tests/1');
        $this->get('/tests/1')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_failures_are_saved_without_retries(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'secret-provider-detail']], 429)]);
        $run = $this->execute();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('rate or credit', $run->response->error_details);
        $this->assertStringNotContainsString('secret-provider-detail', $run->response->error_details);
        Http::assertSentCount(1);
    }

    public function test_connection_errors_and_invalid_responses_are_saved(): void
    {
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $run = $this->execute();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('timed out', $run->response->error_details);
        Http::fake(['api.openai.com/*' => Http::response(['unexpected' => true])]);
        $run = $this->execute();
        $this->assertSame('failed', $run->status);
    }

    public function test_incomplete_answer_does_not_inflate_dashboard(): void
    {
        $payload = $this->payload();
        $payload['status'] = 'incomplete';
        Http::fake(['api.openai.com/*' => Http::response($payload)]);
        $run = $this->execute();
        $this->assertSame('incomplete', $run->status);
        $this->assertNotNull($run->response->text);
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertDontSee('100%');
    }

    public function test_configuration_and_daily_limit_prevent_spending(): void
    {
        config(['intelligence.openai_key' => null]);
        try {
            $this->execute();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Configure', $e->getMessage());
        }
        config(['intelligence.openai_key' => 'test-key', 'intelligence.daily_limit' => 0]);
        try {
            $this->execute();
            $this->fail('Expected limit failure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Daily', $e->getMessage());
        } Http::assertNothingSent();
    }

    public function test_recognition_does_not_confuse_hotels_or_negative_mentions(): void
    {
        $recognizer = app(HotelRecognition::class);
        $mentions = $recognizer->detect("1. Hanging Gardens of Bali\nNandini Jungle Resort is not recommended.\nNandini Jungle by Hanging Gardens was mentioned for context.");
        $this->assertCount(3, $mentions);
        $this->assertFalse($mentions[0]['is_nandini']);
        $this->assertFalse($mentions[1]['is_recommendation']);
        $this->assertFalse($mentions[2]['is_recommendation']);
    }

    public function test_ai_html_is_escaped(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->payload('<script>alert(1)</script>'))]);
        $run = $this->execute();
        $this->actingAs($this->admin())->get('/tests/'.$run->id)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_unknown_submission_token_is_rejected_without_an_api_call(): void
    {
        $this->actingAs($this->admin());
        $this->post('/tests', ['provider_id' => AiProvider::first()->id, 'prompt_id' => SearchPrompt::first()->id, 'market_id' => MarketScenario::first()->id, 'model' => config('intelligence.model'), 'submission_id' => (string) Str::uuid()])->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_stale_runs_are_marked_interrupted_when_reopened(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->payload())]);
        $run = $this->execute();
        $run->update(['status' => 'running', 'executed_at' => now()->subMinutes(5)]);
        $this->actingAs($this->admin())->get('/tests/'.$run->id)->assertOk()->assertSee('Execution interrupted');
        $this->assertSame('interrupted', $run->fresh()->status);
    }
}
