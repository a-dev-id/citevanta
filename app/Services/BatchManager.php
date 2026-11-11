<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\SpendingSetting;
use App\Models\TestBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchManager
{
    public function __construct(private RequestPricing $pricing, private CostControl $costs) {}

    public function preview(User $user, array $data): TestBatch
    {
        app(TenantContext::class)->assertManager($user);
        if ($existing = TestBatch::where('creation_id', $data['creation_id'])->first()) {
            return $existing;
        }
        $settings = $this->costs->settings();
        $count = count($data['prompt_ids']) * count($data['market_ids']) * $data['repetitions'];
        app(OrganizationQuota::class)->assertBatch($count, (int) $data['repetitions']);
        if ($count > $settings->batch_request_limit) {
            throw ValidationException::withMessages(['batch' => 'This combination exceeds the per-batch request limit of '.$settings->batch_request_limit.'.']);
        }
        $prompts = SearchPrompt::whereIn('id', $data['prompt_ids'])->where('active', true)->get();
        $markets = MarketScenario::whereIn('id', $data['market_ids'])->get();
        if ($prompts->count() !== count($data['prompt_ids']) || $markets->count() !== count($data['market_ids'])) {
            throw ValidationException::withMessages(['batch' => 'Select active prompts and supported market scenarios.']);
        }
        $simulation = app(SimulatedMonitoring::class)->available();
        $model = app(SimulatedMonitoring::class)->model();
        $total = 0;
        $reserve = 0;
        $price = null;
        $reason = null;
        try {
            foreach ($simulation ? [] : $prompts as $prompt) {
                $q = $this->pricing->quote($prompt->text, $model, $settings->safety_percent);
                $total += $q['estimate'] * $markets->count() * $data['repetitions'];
                $reserve += $q['reserve'] * $markets->count() * $data['repetitions'];
                $price = $q['pricing'];
            }
        } catch (ValidationException) {
            $total = null;
            $reserve = null;
            $reason = 'Cost estimates are unavailable. This batch cannot start until a fresh, priced preview is created.';
        }

        return DB::transaction(function () use ($user, $data, $settings, $count, $prompts, $markets, $model, $total, $reserve, $price, $reason, $simulation) {
            $provider = AiProvider::where('identifier', $simulation ? 'simulated' : 'openai')->where('enabled', true)->firstOrFail();
            $batch = TestBatch::firstOrCreate(['creation_id' => $data['creation_id']], ['user_id' => $user->id, 'name' => $data['name'], 'status' => 'draft', 'model' => $model, 'ai_provider_id' => $provider->id, 'configuration' => ['simulated' => $simulation, 'prompt_ids' => $data['prompt_ids'], 'market_ids' => $data['market_ids'], 'repetitions' => (int) $data['repetitions'], 'safety_percent' => $settings->safety_percent, 'max_attempts' => config('spending.max_attempts')], 'pricing_snapshot' => $price, 'request_count' => $count, 'request_limit' => $settings->batch_request_limit, 'estimated_micros' => $total, 'reserved_estimate_micros' => $reserve, 'status_note' => $reason]);
            if ($batch->items()->exists()) {
                return $batch;
            }
            foreach ($prompts as $prompt) {
                foreach ($markets as $market) {
                    for ($i = 1; $i <= $data['repetitions']; $i++) {
                        $batch->items()->create(['search_prompt_id' => $prompt->id, 'market_scenario_id' => $market->id, 'repetition' => $i, 'prompt_snapshot' => $prompt->only(['id', 'prompt_id', 'category', 'text', 'version', 'active']), 'market_snapshot' => $market->only(['id', 'name', 'country', 'language', 'search_settings']), 'status' => 'pending']);
                    }
                }
            }

            return $batch;
        });
    }

    public function start(TestBatch $batch): void
    {
        if (app(SimulatedMonitoring::class)->available()) {
            app(SimulatedMonitoring::class)->start($batch);

            return;
        }
        DB::transaction(function () use ($batch) {
            app(OrganizationQuota::class)->assertBatch($batch->request_count, (int) $batch->configuration['repetitions']);
            $settings = $this->costs->settings();
            SpendingSetting::whereKey($settings->id)->lockForUpdate()->firstOrFail();
            $batch = TestBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->model !== config('intelligence.model')) {
                throw ValidationException::withMessages(['cost' => 'The monitoring model changed. Create a fresh preview with the current model.']);
            }
            if ($batch->pilot_key) {
                app(PilotStudy::class)->assertApproval($batch);
            }
            if ($batch->status === 'running') {
                return;
            }
            if (! in_array($batch->status, ['draft', 'paused'], true)) {
                throw ValidationException::withMessages(['batch' => 'Only draft or paused batches can start.']);
            }
            $price = $this->pricing->snapshot($batch->model);
            if (! $batch->pricing_snapshot || $price != $batch->pricing_snapshot) {
                throw ValidationException::withMessages(['cost' => 'Create a fresh preview with the current pricing configuration.']);
            }
            $pending = $batch->items()->whereIn('status', ['pending', 'retry_wait'])->get();
            if ($pending->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There is no pending work to resume.']);
            }
            if ($batch->runs()->count() + $pending->count() > min($batch->request_limit, $settings->batch_request_limit)) {
                throw ValidationException::withMessages(['batch' => 'Remaining work would exceed the batch API request cap, including earlier attempts.']);
            }
            $reserve = 0;
            foreach ($pending as $item) {
                $reserve += $this->pricing->quote($item->prompt_snapshot['text'], $batch->model, $settings->safety_percent)['reserve'];
            }
            $this->costs->assertAvailable($reserve);
            $organizationBalance = $this->costs->tenantBalance();
            if ($organizationBalance['remaining'] === null || $organizationBalance['settings']->monthly_limit_micros === 0 || $reserve > $organizationBalance['remaining']) {
                throw ValidationException::withMessages(['quota' => 'Organization spending protection prevents this campaign. Contact your platform administrator.']);
            }
            app(CreditLedger::class)->reserveBatch($batch);
            $batch->update(['status' => 'running', 'status_note' => null, 'started_at' => $batch->started_at ?? now(), 'finished_at' => null]);
        });
    }

    public function cancel(TestBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch = TestBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, ['cancelled', 'completed', 'completed_with_errors'], true)) {
                return;
            }
            app(CreditLedger::class)->cancelBatch($batch);
            $batch->items()->whereIn('status', ['pending', 'retry_wait'])->update(['status' => 'cancelled', 'status_note' => 'Cancelled before dispatch.']);
            $active = $batch->items()->where('status', 'processing')->exists();
            $batch->update(['status' => $active ? 'cancelling' : 'cancelled', 'status_note' => $active ? 'Pending requests stopped. An in-flight request may still finish.' : 'Pending requests cancelled.', 'finished_at' => $active ? null : now()]);
        });
    }

    public function refresh(TestBatch $batch): void
    {
        $batch->refresh();
        $states = $batch->items()->pluck('status');
        if ($states->contains('processing') || $states->contains('pending') || $states->contains('retry_wait')) {
            return;
        }
        $batch->update(['status' => in_array($batch->status, ['cancelled', 'cancelling'], true) ? 'cancelled' : ($states->contains('failed') || $states->contains('interrupted') ? 'completed_with_errors' : 'completed'), 'finished_at' => now()]);
    }
}
