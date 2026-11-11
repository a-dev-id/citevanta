<?php

namespace App\Services;

use App\Models\AiProvider;
use App\Models\CreditPeriod;
use App\Models\PlanChange;
use App\Services\Ai\ProviderManager;
use Illuminate\Validation\ValidationException;

class PlanCatalog
{
    public function definition(string $key): array
    {
        $plan = config('plans.definitions.'.$key);
        if (! is_array($plan)) {
            throw ValidationException::withMessages(['quota' => 'No valid subscription is configured.']);
        }
        foreach (['price_cents', 'brands', 'members', 'monthly_searches', 'batch_size', 'repetitions', 'retention_days'] as $field) {
            if (! is_int($plan[$field] ?? null) || $plan[$field] < 0) {
                throw ValidationException::withMessages(['quota' => 'Subscription limits are invalid.']);
            }
        }
        if (! is_array($plan['providers'] ?? null) || ! is_array($plan['features'] ?? null) || ! is_string(config('plans.version'))) {
            throw ValidationException::withMessages(['quota' => 'Subscription catalog metadata is incomplete.']);
        }

        return [...$plan, 'key' => $key, 'catalog_version' => config('plans.version')];
    }

    public function current(): array
    {
        $org = app(TenantContext::class)->organization();
        $period = CreditPeriod::where('organization_id', $org->id)->where('starts_at', now('UTC')->startOfMonth())->first();
        $change = $period ? PlanChange::where('organization_id', $org->id)->where('credit_period_id', $period->id)->latest('id')->first() : null;
        $snapshot = $change?->after_snapshot ?? $period?->plan_snapshot;

        return $snapshot && ($snapshot['key'] ?? null) === $org->plan_key ? $snapshot : $this->definition($org->plan_key);
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->current()['features'] ?? [], true) && in_array($feature, config('plans.features_available'), true);
    }

    public function authorize(string $feature): void
    {
        abort_unless($this->allows($feature), 403, 'This feature is not included in your current plan.');
    }

    public function platforms(): array
    {
        if (app(SimulatedMonitoring::class)->available()) {
            return AiProvider::where('identifier', 'simulated')->where('enabled', true)->exists() ? ['simulated'] : [];
        }
        $plan = $this->current();
        $providers = AiProvider::where('enabled', true)->whereIn('identifier', array_intersect($plan['providers'], config('plans.implemented_providers')))->get()->filter(function ($provider) {
            try {
                app(ProviderManager::class)->resolve($provider);

                return true;
            } catch (\InvalidArgumentException) {
                return false;
            }
        })->pluck('identifier')->all();

        return array_slice($providers, 0, $plan['platform_limit'] ?? count($providers));
    }
}
