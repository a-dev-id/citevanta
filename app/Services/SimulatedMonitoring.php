<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\BatchItem;
use App\Models\MarketScenario;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\SimulatedProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SimulatedMonitoring
{
    public function available(): bool
    {
        $context = app(TenantContext::class);
        if (! app()->environment(['local', 'testing']) || ! config('local_testing.simulated_execution') || ! $context->organizationId) {
            return false;
        }
        $organization = $context->organization();

        return $organization->is_test_fixture && $organization->status === 'active' && in_array($organization->slug, array_map(fn ($plan) => 'citevanta-test-'.$plan, ['free', 'starter', 'pro', 'agency']), true);
    }

    public function assertAvailable(): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['simulation' => 'Simulated monitoring is only available in dedicated local testing workspaces.']);
        }
    }

    public function model(): string
    {
        return $this->available() ? SimulatedProvider::MODEL : config('intelligence.model');
    }

    public function provider(): string
    {
        return $this->available() ? 'simulated' : 'openai';
    }

    private function assertCapacity(): void
    {
        $organization = app(TenantContext::class)->organization();
        $plan = app(PlanCatalog::class)->current();
        abort_unless($organization->brands()->where('id', app(TenantContext::class)->brandId)->where('active', true)->exists(), 403);
        if ($organization->brands()->where('active', true)->count() > $plan['brands'] || $organization->members()->count() > $plan['members']) {
            throw ValidationException::withMessages(['credits' => 'Existing brands or members exceed your subscription. Review Plan & Usage.']);
        }
    }

    public function start(TestBatch $batch): void
    {
        $this->assertAvailable();
        $context = app(TenantContext::class);
        app(TenantContext::class)->assertManager(User::findOrFail($context->actorId));
        DB::transaction(function () use ($batch) {
            Organization::whereKey(app(TenantContext::class)->organizationId)->lockForUpdate()->firstOrFail();
            $batch = TestBatch::lockForUpdate()->findOrFail($batch->id);
            abort_if($batch->pilot_key, 403);
            $this->assertCapacity();
            app(OrganizationQuota::class)->assertBatch($batch->request_count, (int) $batch->configuration['repetitions']);
            if (! in_array($batch->status, ['draft', 'paused', 'running'], true)) {
                throw ValidationException::withMessages(['campaign' => 'Only draft or paused campaigns can start.']);
            }
            if ($batch->status === 'draft' && ! $batch->runs()->exists()) {
                $provider = AiProvider::where('identifier', 'simulated')->where('enabled', true)->firstOrFail();
                $batch->update(['ai_provider_id' => $provider->id, 'model' => SimulatedProvider::MODEL, 'configuration' => array_replace($batch->configuration, ['simulated' => true, 'max_attempts' => 1]), 'pricing_snapshot' => null, 'estimated_micros' => 0, 'reserved_estimate_micros' => 0]);
            }
            $this->assertCampaign($batch);
            app(CreditLedger::class)->reserveBatch($batch);
            $batch->update(['status' => 'running', 'status_note' => null, 'started_at' => $batch->started_at ?? now(), 'finished_at' => null]);
        });
    }

    private function assertCampaign(TestBatch $batch): void
    {
        $this->assertAvailable();
        abort_unless($batch->organization_id === app(TenantContext::class)->organizationId && $batch->monitored_brand_id === app(TenantContext::class)->brandId, 404);
        if ($batch->provider->identifier !== 'simulated' || $batch->model !== SimulatedProvider::MODEL || ! ($batch->configuration['simulated'] ?? false) || $batch->pilot_key) {
            throw ValidationException::withMessages(['simulation' => 'Create a new simulated campaign preview.']);
        }
    }

    public function process(TestBatch $batch, int $limit = 3): int
    {
        $this->assertCampaign($batch);
        app(TenantContext::class)->assertManager(User::findOrFail(app(TenantContext::class)->actorId));
        $processed = 0;
        for ($i = 0; $i < max(1, min(3, $limit)); $i++) {
            $didProcess = DB::transaction(function () use ($batch) {
                Organization::whereKey(app(TenantContext::class)->organizationId)->lockForUpdate()->firstOrFail();
                $current = TestBatch::lockForUpdate()->findOrFail($batch->id);
                if ($current->status !== 'running') {
                    return false;
                }
                app(OrganizationQuota::class)->assertBatch($current->request_count, (int) $current->configuration['repetitions']);
                app(CreditLedger::class)->reserveBatch($current);
                $item = $current->items()->whereIn('status', ['pending', 'retry_wait'])->orderBy('id')->lockForUpdate()->first();
                if (! $item) {
                    return false;
                }
                $item->update(['status' => 'processing', 'attempts' => $item->attempts + 1, 'submission_id' => $item->submission_id ?? (string) Str::uuid(), 'claimed_at' => now()]);
                $run = $this->execute($current->user, $current->provider, SearchPrompt::findOrFail($item->search_prompt_id), MarketScenario::findOrFail($item->market_scenario_id), $current->model, $item->submission_id, $item);
                $item->update(['status' => $run->status === 'completed' ? 'completed' : 'failed', 'test_run_id' => $run->id, 'status_note' => $run->response?->error_details, 'claimed_at' => null]);
                app(BatchManager::class)->refresh($current);

                return true;
            });
            if (! $didProcess) {
                break;
            }
            $processed++;
        }

        return $processed;
    }

    public function execute(User $user, AiProvider $provider, SearchPrompt $prompt, MarketScenario $market, string $model, string $submission, ?BatchItem $item = null): TestRun
    {
        $this->assertAvailable();
        app(TenantContext::class)->assertManager($user);
        abort_unless($provider->enabled && $provider->identifier === 'simulated' && $model === SimulatedProvider::MODEL, 403);

        return DB::transaction(function () use ($user, $provider, $prompt, $market, $model, $submission, $item) {
            $organization = Organization::whereKey(app(TenantContext::class)->organizationId)->lockForUpdate()->firstOrFail();
            $this->assertAvailable();
            $this->assertCapacity();
            foreach ([$prompt, $market] as $record) {
                $record->newQuery()->findOrFail($record->id);
            }
            if ($existing = TestRun::where('submission_id', $submission)->first()) {
                return $existing;
            }
            if ($item) {
                $batch = TestBatch::lockForUpdate()->findOrFail($item->test_batch_id);
                $this->assertCampaign($batch);
                app(OrganizationQuota::class)->assertBatch($batch->request_count, (int) $batch->configuration['repetitions']);
                $claimed = BatchItem::lockForUpdate()->findOrFail($item->id);
                if ($batch->status !== 'running' || $claimed->status !== 'processing' || $claimed->submission_id !== $submission) {
                    throw ValidationException::withMessages(['campaign' => 'This campaign is stopped. No search was processed.']);
                }
            }
            $key = 'simulation:account:'.$user->id;
            $plan = app(PlanCatalog::class)->current();
            if (RateLimiter::tooManyAttempts($key, config('plans.request_rate_per_minute.'.($plan['key'] === 'free' ? 'free' : 'paid')))) {
                throw ValidationException::withMessages(['credits' => 'Account request rate limit reached. Please wait before another search.']);
            }
            $credit = app(CreditLedger::class)->reserve($item ? 'batch:'.$item->id : 'simulation:search:'.$submission, $user->id, 'simulated', $model, $item?->id);
            RateLimiter::hit($key, 60);
            $scenario = TestRun::where('model', SimulatedProvider::MODEL)->count();
            $run = TestRun::create(['is_simulated' => true, 'user_id' => $user->id, 'ai_provider_id' => $provider->id, 'search_prompt_id' => $prompt->id, 'market_scenario_id' => $market->id, 'test_batch_id' => $item?->test_batch_id, 'batch_item_id' => $item?->id, 'submission_id' => $submission, 'model' => $model, 'prompt_text' => $item?->prompt_snapshot['text'] ?? $prompt->text, 'prompt_version' => $item?->prompt_snapshot['version'] ?? $prompt->version, 'market_snapshot' => $item?->market_snapshot ?? $market->only(['name', 'country', 'language', 'search_settings']), 'request_settings' => ['simulated' => true], 'status' => 'running', 'repetition' => $item?->repetition ?? 1, 'executed_at' => now()]);
            $credit->update(['test_run_id' => $run->id]);
            try {
                $data = app(SimulatedProvider::class)->execute(['scenario' => $scenario]);
                $response = $run->response()->create(['text' => $data['text'], 'status' => 'completed', 'raw_metadata' => $data, 'api_response_id' => 'sim_'.$submission]);
                $response->citations()->createMany($data['citations']);
                $run->update(['status' => 'completed']);
                $credit->update(['status' => 'used']);
                app(VisibilityAnalysis::class)->analyze($response);
            } catch (\Throwable $exception) {
                $run->response()->updateOrCreate([], ['status' => 'failed', 'raw_metadata' => ['simulated' => true], 'error_details' => 'Simulated search failed. Its credits were released. No provider request was sent.']);
                $run->update(['status' => 'failed']);
                $credit->update(['status' => 'released']);
            }

            return $run->fresh();
        });
    }
}
