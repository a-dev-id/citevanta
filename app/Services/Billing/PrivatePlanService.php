<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Team;
use App\Services\Plans\PlanLimits;
use Carbon\CarbonInterface;

/**
 * Admin > Teams > Private plan: put a team on the private plan with its
 * own monthly price (IDR and USD) and limits. Renewal invoices then use
 * that price (PriceCalculator) like any other plan.
 */
final class PrivatePlanService
{
    /**
     * @param  array{price_usd: ?int, price_idr: ?int, currency: string, interval: string, paid_until: ?CarbonInterface, limits: array<string, int|null>}  $data
     */
    public function apply(Team $team, array $data): void
    {
        $editable = (array) config('citevanta.editable_limits', []);
        $limits = array_filter(array_intersect_key($data['limits'], array_flip($editable)), fn ($value) => $value !== null && $value !== '');
        $limits = array_map('intval', $limits);
        $defaults = PlanLimits::for(Plan::Private);
        $hotels = (int) ($limits['hotels'] ?? $defaults->hotels);

        $team->forceFill([
            'plan' => Plan::Private,
            'plan_limits' => $limits === [] ? null : $limits,
            'private_price_usd' => $data['price_usd'],
            'private_price_idr' => $data['price_idr'],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::from($data['interval']),
            'currency' => $data['currency'],
            'hotel_quantity' => max(1, $hotels),
            'current_period_starts_at' => $team->current_period_starts_at ?? now(),
            'current_period_ends_at' => $data['paid_until'] ?? $team->current_period_ends_at ?? now()->addMonthNoOverflow(),
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'is_comped' => false,
            'next_plan' => null,
            'next_interval' => null,
            'next_quantity' => null,
            'payment_method' => PaymentMethod::Manual,
            'trial_ends_at' => null,
        ])->save();
    }
}
