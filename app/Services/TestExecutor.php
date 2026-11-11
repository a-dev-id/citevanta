<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\BatchItem;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiRequestException;
use App\Services\Ai\ProviderManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class TestExecutor
{
    public function __construct(private ProviderManager $providers, private HotelRecognition $recognition, private CostControl $costs) {}

    public function execute(User $user, AiProvider $provider, SearchPrompt $prompt, MarketScenario $market, string $model, string $submission, ?BatchItem $item = null): TestRun
    {
        app(TenantContext::class)->assertManager($user);
        foreach ([$prompt, $market] as $record) {
            $record->newQuery()->findOrFail($record->id);
        }
        if ($provider->identifier === 'simulated') {
            return app(SimulatedMonitoring::class)->execute($user, $provider, $prompt, $market, $model, $submission, $item);
        }
        $adapter = $this->providers->resolve($provider);
        if (! config('intelligence.openai_key')) {
            throw ValidationException::withMessages(['provider' => 'Configure NANDINI_OPENAI_API_KEY (or OPENAI_API_KEY) on the server before running a test.']);
        }
        $lock = Cache::lock('intelligence:execution', 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['test' => 'A test is already in progress. Please wait before submitting another.']);
        }
        try {
            if ($existing = TestRun::where('submission_id', $submission)->first()) {
                return $existing;
            }
            if (TestRun::withoutGlobalScopes()->where('is_simulated', false)->whereDate('executed_at', today())->count() >= config('intelligence.daily_limit')) {
                throw ValidationException::withMessages(['test' => 'Daily test limit reached.']);
            }
            TestRun::where('status', 'running')->where('executed_at', '<', now()->subMinutes(3))->each(function (TestRun $run) {
                $run->update(['status' => 'interrupted']);
                $run->response()->updateOrCreate([], ['status' => 'interrupted', 'error_details' => 'Execution interrupted. Provider processing is unknown; no automatic retry was made.']);
            });
            $settings = ['model' => $model, 'input' => $prompt->text, 'instructions' => 'Answer in '.$market->language.'. Use web search to answer the query. Clearly distinguish recommendations from businesses only mentioned for context. Cite sources.', 'tools' => [['type' => 'web_search', 'user_location' => $market->search_settings]], 'tool_choice' => 'required', 'max_output_tokens' => config('intelligence.max_output_tokens'), 'max_tool_calls' => 1, 'store' => false];
            $attributes = ['user_id' => $user->id, 'ai_provider_id' => $provider->id, 'search_prompt_id' => $prompt->id, 'market_scenario_id' => $market->id, 'submission_id' => $submission, 'model' => $model, 'prompt_version' => $prompt->version, 'prompt_text' => $prompt->text, 'market_snapshot' => $market->only(['name', 'country', 'language', 'search_settings']), 'request_settings' => $settings, 'status' => 'running', 'repetition' => TestRun::where('search_prompt_id', $prompt->id)->where('prompt_version', $prompt->version)->where('market_scenario_id', $market->id)->where('ai_provider_id', $provider->id)->where('model', $model)->count() + 1, 'executed_at' => now()];
            if ($item) {
                $attributes['test_batch_id'] = $item->test_batch_id;
                $attributes['batch_item_id'] = $item->id;
                $attributes['repetition'] = $item->repetition;
            }
            $run = $this->costs->reserve($attributes, $item);
            try {
                if (function_exists('set_time_limit')) {
                    set_time_limit(config('intelligence.timeout') + 20);
                }
                $data = app(PaidExecutionGate::class)->forSearch($run, fn () => $adapter->execute($settings));
                DB::transaction(function () use ($run, $data, $model) {
                    $text = '';
                    $citations = [];
                    foreach ($data['output'] as $item) {
                        if (($item['type'] ?? '') !== 'message') {
                            continue;
                        }
                        foreach ($item['content'] ?? [] as $part) {
                            if (($part['type'] ?? '') !== 'output_text') {
                                continue;
                            }
                            if ($text !== '') {
                                $text .= "\n\n";
                            }
                            $offset = mb_strlen($text);
                            $text .= $part['text'] ?? '';
                            foreach ($part['annotations'] ?? [] as $annotation) {
                                if (($annotation['type'] ?? '') !== 'url_citation') {
                                    continue;
                                }
                                $url = $annotation['url'] ?? '';
                                $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
                                if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https']) || ! $host || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) {
                                    continue;
                                }
                                $official = collect([parse_url(app(CurrentWorkspace::class)->brand()->official_website, PHP_URL_HOST)])->contains(fn (string $domain) => $host === $domain || str_ends_with($host, '.'.$domain));
                                $citations[] = ['url' => $url, 'title' => $annotation['title'] ?? null, 'domain' => $host, 'is_official' => $official, 'start_index' => isset($annotation['start_index']) ? $offset + $annotation['start_index'] : null, 'end_index' => isset($annotation['end_index']) ? $offset + $annotation['end_index'] : null];
                            }
                        }
                    }
                    $searched = collect($data['output'])->contains(fn (array $item) => ($item['type'] ?? '') === 'web_search_call' && ($item['status'] ?? '') === 'completed');
                    $complete = ($data['status'] ?? '') === 'completed' && trim($text) !== '' && $searched;
                    $status = $complete ? 'completed' : 'incomplete';
                    $response = $run->response()->create(['text' => $text, 'raw_metadata' => $data, 'api_response_id' => $data['id'], 'status' => $status, 'error_details' => $complete ? null : 'Response incomplete, empty, or web search was not completed. See saved metadata.']);
                    $response->citations()->createMany($citations);
                    $response->mentions()->createMany($this->recognition->detect($text));
                    $usage = $data['usage'] ?? [];
                    $run->usage()->create(['model' => $data['model'] ?? $model, 'input_tokens' => $usage['input_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null, 'total_tokens' => $usage['total_tokens'] ?? null, 'estimated_cost' => null]);
                    $run->update(['status' => $status]);
                });
            } catch (Throwable $exception) {
                $knownFailure = $exception instanceof OpenAiRequestException;
                $message = $knownFailure ? $exception->getMessage() : 'The test could not be saved or processed. No automatic retry was made.';
                $diagnostics = $knownFailure ? $exception->diagnostics : ['error' => ['code' => 'processing_error']];
                Log::warning('AI search test failed', ['organization_id' => $run->organization_id, 'monitored_brand_id' => $run->monitored_brand_id, 'test_run_id' => $run->id, 'provider' => $provider->identifier, ...$diagnostics]);
                $run->response()->updateOrCreate([], ['status' => 'failed', 'error_details' => $message, 'raw_metadata' => $diagnostics]);
                $run->update(['status' => 'failed']);
            }

            $this->costs->settle($run->fresh());

            return $run->fresh();
        } finally {
            $lock->release();
        }
    }
}
