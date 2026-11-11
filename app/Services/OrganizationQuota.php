<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OrganizationQuota
{
    public function __construct(private TenantContext $context) {}

    public function plan(): array
    {
        $plan = app(PlanCatalog::class)->current();
        if (! is_array($plan)) {
            $this->blocked('No valid plan limits are configured.');
        }
        foreach (['brands', 'members', 'monthly_searches', 'batch_size', 'repetitions', 'retention_days'] as $key) {
            if (! isset($plan[$key]) || ! is_int($plan[$key]) || $plan[$key] < 0) {
                $this->blocked('Plan limits are incomplete or invalid.');
            }
        }
        if (! isset($plan['providers']) || ! is_array($plan['providers'])) {
            $this->blocked('Provider entitlements are unavailable.');
        }

        return $plan;
    }

    public function assertCapacity(string $resource, int $additional = 1): void
    {
        $org = Organization::lockForUpdate()->findOrFail($this->context->organizationId);
        $limit = $this->plan()[$resource] ?? 0;
        $used = $resource === 'brands' ? DB::table('monitored_brands')->where('organization_id', $org->id)->where('active', true)->count() : DB::table('organization_user')->where('organization_id', $org->id)->count();
        if ($used + $additional > $limit) {
            $this->blocked('The configured '.$resource.' limit is reached.');
        }
    }

    public function assertBatch(int $count, int $repetitions, string $provider = 'openai'): void
    {
        if (! app(PlanCatalog::class)->allows('campaigns')) {
            $this->blocked('Batch size, repetitions, or provider exceeds this organization’s configured limits.');
        }
        $plan = $this->plan();
        if ($count > $plan['batch_size'] || $repetitions > $plan['repetitions'] || ! in_array($provider, $plan['providers'], true)) {
            $this->blocked('Batch size, repetitions, or provider exceeds this organization’s configured limits.');
        }
    }

    public function committed(): int
    {
        return (int) DB::table('cost_entries')->where('organization_id', $this->context->organizationId)->where('created_at', '>=', now('UTC')->startOfMonth())->where('created_at', '<', now('UTC')->startOfMonth()->addMonth())->sum('committed_micros');
    }

    public function assertRequest(string $provider, int $reserve, ?int $userId = null): void
    {
        $org = Organization::lockForUpdate()->findOrFail($this->context->organizationId);
        if ($org->is_test_fixture) {
            $this->blocked('This demonstration workspace uses simulated results. Live monitoring is unavailable.');
        }
        $userId ??= $this->context->actorId;
        if (! DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $userId)->whereIn('role', ['owner', 'administrator'])->exists() || ! DB::table('monitored_brands')->where('id', $this->context->brandId)->where('organization_id', $org->id)->where('active', true)->exists()) {
            $this->blocked('Request creator membership or brand access is no longer active.');
        }
        $plan = $this->plan();
        if ($org->status !== 'active' || ! in_array($provider, app(PlanCatalog::class)->platforms(), true)) {
            $this->blocked('Organization subscription or platform permission is unavailable.');
        }
        if ($org->brands()->where('active', true)->count() > $plan['brands'] || $org->members()->count() > $plan['members']) {
            $this->blocked('Existing brands or members exceed your subscription. Review Plan & Usage.');
        }
        app(CreditLedger::class)->assertAvailable(app(CreditLedger::class)->weight($provider, config('intelligence.model')));
        app(SubscriptionFinancialProtection::class)->assertAvailable($reserve);
        $actor = User::findOrFail($userId);
        if ($plan['key'] === 'free' && ! $actor->hasVerifiedEmail()) {
            $this->blocked('Verify your email address before using Free search credits.');
        }
        $key = 'credits:account:'.$userId;
        if (RateLimiter::tooManyAttempts($key, config('plans.request_rate_per_minute.'.($plan['key'] === 'free' ? 'free' : 'paid')))) {
            $this->blocked('Account request rate limit reached. Please wait before another request.');
        }
        RateLimiter::hit($key, 60);
        $opening = $this->opening();
        if ($opening === null || app(SubscriptionFinancialProtection::class)->limit() === 0 || $this->committed() + $opening + $reserve > app(SubscriptionFinancialProtection::class)->limit()) {
            $this->blocked('Organization spending allowance is disabled, unreconciled, or insufficient for the safety reservation.');
        }
    }

    public function opening(): ?int
    {
        $legacy = DB::table('test_runs')->where('is_simulated', false)->where('organization_id', $this->context->organizationId)->where('executed_at', '>=', now('UTC')->startOfMonth())->where('executed_at', '<', now('UTC')->startOfMonth()->addMonth())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cost_entries')->whereColumn('cost_entries.test_run_id', 'test_runs.id'))->exists();

        return $legacy ? DB::table('budget_months')->where('month', now('UTC')->format('Y-m'))->value('opening_micros') : 0;
    }

    public function monthlyCount(string $table): int
    {
        return DB::table($table)->where('organization_id', $this->context->organizationId)->where($table === 'test_runs' ? 'executed_at' : 'created_at', '>=', now('UTC')->startOfMonth())->where($table === 'test_runs' ? 'executed_at' : 'created_at', '<', now('UTC')->startOfMonth()->addMonth())->count();
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['quota' => $message]);
    }
}
