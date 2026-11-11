<?php

namespace App\Services;

use App\Models\CreditEntry;
use App\Models\CreditPeriod;
use App\Models\ImprovementExplanation;
use App\Models\Organization;
use App\Models\PlanChange;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditLedger
{
    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['credits' => $message]);
    }

    public function weight(string $provider, string $model): int
    {
        $weights = config('plans.credit_weights.'.$provider, []);
        $weight = $weights[$model] ?? $weights['*'] ?? null;
        if (! is_int($weight) || $weight < 1) {
            $this->blocked('A current credit weight is unavailable.');
        }

        return $weight;
    }

    public function period(): CreditPeriod
    {
        $id = app(TenantContext::class)->organization()->id;

        return DB::transaction(function () use ($id) {
            $org = Organization::lockForUpdate()->findOrFail($id);
            $start = now('UTC')->startOfMonth();
            if ($period = CreditPeriod::where('organization_id', $id)->where('starts_at', $start)->first()) {
                return $period;
            }
            $plan = app(PlanCatalog::class)->definition($org->plan_key);
            CreditEntry::where('organization_id', $id)->where('status', 'reserved')->whereNull('test_run_id')->whereIn('batch_item_id', DB::table('batch_items')->where('organization_id', $id)->whereIn('status', ['pending', 'retry_wait', 'cancelled'])->select('id'))->update(['status' => 'released']);
            $period = CreditPeriod::create(['organization_id' => $id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMonth(), 'plan_key' => $org->plan_key, 'plan_snapshot' => $plan, 'allowance' => $plan['monthly_searches']]);
            foreach (['test_runs' => 'test_run_id', 'improvement_explanations' => 'improvement_explanation_id'] as $table => $foreign) {
                $date = $table === 'test_runs' ? 'executed_at' : 'created_at';
                foreach (DB::table($table)->where('organization_id', $id)->where($date, '>=', $period->starts_at)->where($date, '<', $period->ends_at)->whereIn('status', ['completed', 'running', 'interrupted'])->orderBy('id')->get() as $run) {
                    if ($table === 'test_runs' && $run->is_simulated) {
                        continue;
                    }
                    $key = $table === 'test_runs' && $run->batch_item_id ? 'batch:'.$run->batch_item_id : ($table === 'test_runs' ? 'search:' : 'explain:').$run->submission_id;
                    CreditEntry::updateOrCreate(['organization_id' => $id, 'credit_period_id' => $period->id, 'operation_key' => $key], ['user_id' => $run->user_id, 'credits' => $this->weight('openai', $run->model), 'status' => $run->status === 'completed' ? 'used' : 'reserved', 'plan_key' => $org->plan_key, 'provider' => 'openai', 'model' => $run->model, $foreign => $run->id, 'batch_item_id' => $table === 'test_runs' ? $run->batch_item_id : null]);
                }
            }

            return $period;
        });
    }

    public function balance(): array
    {
        $period = $this->period();
        $entries = CreditEntry::where('organization_id', $period->organization_id)->where('credit_period_id', $period->id);
        $used = (int) (clone $entries)->where('status', 'used')->sum('credits');
        $reserved = (int) (clone $entries)->where('status', 'reserved')->sum('credits');

        return ['period' => $period, 'allowance' => $period->allowance, 'used' => $used, 'reserved' => $reserved, 'remaining' => max(0, $period->allowance - $used - $reserved), 'reset' => $period->ends_at];
    }

    public function assertAvailable(int $credits): void
    {
        if ($credits > $this->balance()['remaining']) {
            $this->blocked('Insufficient monthly search credits. Reduce the request count or review Plan & Usage.');
        }
    }

    public function reserve(string $key, int $userId, string $provider, string $model, ?int $item = null): CreditEntry
    {
        return DB::transaction(function () use ($key, $userId, $provider, $model, $item) {
            $context = app(TenantContext::class);
            $org = Organization::lockForUpdate()->findOrFail($context->organizationId);
            $user = User::lockForUpdate()->findOrFail($userId);
            if (! DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $userId)->whereIn('role', ['owner', 'administrator'])->exists()) {
                $this->blocked('Active organization manager membership is required.');
            }
            if ($item && ! DB::table('batch_items')->where('id', $item)->where('organization_id', $org->id)->where('monitored_brand_id', $context->brandId)->exists()) {
                $this->blocked('Select a campaign item in the current workspace.');
            }
            $plan = app(PlanCatalog::class)->current();
            if (! in_array($provider, app(PlanCatalog::class)->platforms(), true)) {
                $this->blocked('This AI platform is not available to your subscription.');
            }
            if ($plan['key'] === 'free' && ! $user->hasVerifiedEmail()) {
                $this->blocked('Verify your email address before using Free search credits.');
            }
            $period = $this->period();
            $entry = CreditEntry::where('organization_id', $org->id)->where('credit_period_id', $period->id)->where('operation_key', $key)->first();
            if (CreditEntry::where('organization_id', $org->id)->where('operation_key', $key)->where('status', 'used')->exists()) {
                $this->blocked('This monitoring response has already used its credit.');
            }
            if ($entry?->status === 'reserved') {
                return $entry;
            }
            $credits = $this->weight($provider, $model);
            $this->assertAvailable($credits);
            if ($plan['key'] === 'free') {
                $accountUsed = CreditEntry::where('user_id', $userId)->where('provider', $provider)->where('plan_key', 'free')->whereIn('status', ['used', 'reserved'])->whereHas('period', fn ($q) => $q->where('starts_at', $period->starts_at))->sum('credits');
                if ($accountUsed + $credits > config('plans.definitions.free.monthly_searches')) {
                    $this->blocked('Free credits are limited per verified account across Free organizations.');
                }
            }

            return CreditEntry::updateOrCreate(['organization_id' => $org->id, 'credit_period_id' => $period->id, 'operation_key' => $key], ['user_id' => $userId, 'credits' => $credits, 'status' => 'reserved', 'plan_key' => $plan['key'], 'provider' => $provider, 'model' => $model, 'batch_item_id' => $item, 'test_run_id' => null, 'improvement_explanation_id' => null]);
        });
    }

    public function requiredForBatch(TestBatch $batch): int
    {
        $period = $this->period();
        $needed = 0;
        foreach ($batch->items()->whereIn('status', ['pending', 'retry_wait'])->get() as $item) {
            if (! CreditEntry::where('organization_id', $batch->organization_id)->where('credit_period_id', $period->id)->where('operation_key', 'batch:'.$item->id)->where('status', 'reserved')->exists()) {
                $needed += $this->weight($batch->provider->identifier, $batch->model);
            }
        }

        return $needed;
    }

    public function reserveBatch(TestBatch $batch): void
    {
        foreach ($batch->items()->whereIn('status', ['pending', 'retry_wait'])->get() as $item) {
            CreditEntry::where('organization_id', $batch->organization_id)->where('operation_key', 'batch:'.$item->id)->where('credit_period_id', '!=', $this->period()->id)->where('status', 'reserved')->update(['status' => 'released']);
            $this->reserve('batch:'.$item->id, $batch->user_id, $batch->provider->identifier, $batch->model, $item->id);
        }
    }

    public function cancelBatch(TestBatch $batch): void
    {
        $ids = $batch->items()->whereIn('status', ['pending', 'retry_wait', 'cancelled'])->pluck('id');
        CreditEntry::where('organization_id', $batch->organization_id)->whereIn('batch_item_id', $ids)->whereNull('test_run_id')->where('status', 'reserved')->update(['status' => 'released']);
    }

    public function settle(TestRun|ImprovementExplanation $run): void
    {
        abort_unless($run->organization_id === app(TenantContext::class)->organizationId, 404);
        $column = $run instanceof TestRun ? 'test_run_id' : 'improvement_explanation_id';
        $entry = CreditEntry::where('organization_id', $run->organization_id)->where($column, $run->id)->lockForUpdate()->first();
        if (! $entry || $entry->status !== 'reserved') {
            return;
        }
        $raw = $run instanceof TestRun ? ($run->response?->raw_metadata ?? []) : ($run->raw_metadata ?? []);
        $status = $run->status === 'completed' ? 'used' : ((in_array($raw['http_status'] ?? null, [400, 401, 403, 404, 429], true) || in_array($raw['error']['code'] ?? null, ['tls_certificate_error', 'paid_execution_denied'], true) || isset($raw['usage'])) ? 'released' : 'reserved');
        $entry->update(['status' => $status]);
    }

    public function assign(string $key, User $actor): void
    {
        abort_unless($actor->is_platform_admin, 403);
        app(TenantContext::class)->assertManager($actor);
        DB::transaction(function () use ($key, $actor) {
            $org = Organization::lockForUpdate()->findOrFail(app(TenantContext::class)->organizationId);
            $target = app(PlanCatalog::class)->definition($key);
            $period = $this->period();
            $before = app(PlanCatalog::class)->current();
            if ($target === $before) {
                return;
            }
            $balance = $this->balance();
            if (DB::table('monitored_brands')->where('organization_id', $org->id)->where('active', true)->count() > $target['brands'] || $org->members()->count() > $target['members'] || $target['monthly_searches'] < $balance['used'] + $balance['reserved']) {
                $this->blocked('Plan change blocked: existing brands, members, or committed credits exceed the selected allowance. Remove unused capacity or wait for reset.');
            }
            PlanChange::create(['organization_id' => $org->id, 'credit_period_id' => $period->id, 'user_id' => $actor->id, 'before_snapshot' => $before, 'after_snapshot' => $target]);
            $period->update(['allowance' => $target['monthly_searches']]);
            $org->forceFill(['plan_key' => $key])->save();
        });
    }
}
