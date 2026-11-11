<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SubscriptionFinancialProtection
{
    public function ceiling(): int
    {
        $plan = app(PlanCatalog::class)->current();
        $model = config('intelligence.model');
        $weight = app(CreditLedger::class)->weight('openai', $model);
        $quote = app(RequestPricing::class)->quote('', $model, app(CostControl::class)->settings()->safety_percent);

        return min(intdiv($plan['monthly_searches'], $weight) * $quote['reserve'], (int) config('spending.organization_exposure_ceiling_micros'));
    }

    public function limit(): int
    {
        $organization = app(TenantContext::class)->organization();

        return config('spending.automatic_organization_limits') ? $this->ceiling() : min($organization->monthly_limit_micros, $this->ceiling());
    }

    public function assertAvailable(int $reserve): void
    {
        if (app(OrganizationQuota::class)->committed() + $reserve > $this->ceiling()) {
            throw ValidationException::withMessages(['service' => 'Monitoring is currently unavailable. Please try again later or contact support.']);
        }
    }
}
