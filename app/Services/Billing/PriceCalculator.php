<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\Team;
use App\Services\Plans\PlanCatalog;
use Carbon\CarbonInterface;

/**
 * Prices for invoices: plan price per hotel per month x hotels x months
 * (annual = pricing.annual_months x monthly), minus the group or agency
 * discount, plus or including tax (billing.tax).
 */
final class PriceCalculator
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    /**
     * @throws BillingNotAllowed when the plan has no price in this currency
     */
    public function quote(Plan $plan, BillingInterval $interval, int $hotels, string $currency, ?Team $team = null): Quote
    {
        // Private: one price per month for the team, set by an admin (no per-hotel price, no discount).
        if ($plan === Plan::Private) {
            $monthly = $team?->{'private_price_'.$currency};

            if ($monthly === null) {
                throw new BillingNotAllowed('This team has no private plan price in '.strtoupper($currency).' yet.');
            }

            $unit = Money::fromMajor($monthly, $currency);
            $months = $interval->chargedMonths();

            return $this->withTax($currency, $unit, 1, $months, $unit * $months, 0, 0);
        }

        $monthly = $plan->isPaid() ? ($this->catalog->monthly($plan)[$currency] ?? null) : null;

        if ($monthly === null) {
            throw new BillingNotAllowed("The {$plan->label()} plan has no ".strtoupper($currency).' price yet.');
        }

        $hotels = max(1, $hotels);
        $unit = Money::fromMajor($monthly, $currency);
        $months = $interval->chargedMonths();
        $subtotal = $unit * $hotels * $months;
        $percent = $this->discountPercent($plan, $hotels);

        return $this->withTax($currency, $unit, $hotels, $months, $subtotal, $percent, (int) round($subtotal * $percent / 100));
    }

    /**
     * An amount that is not a plan price (an upgrade's pro-rata difference), with tax applied.
     */
    public function adjustment(int $net, string $currency, int $hotels = 1): Quote
    {
        return $this->withTax($currency, max(0, $net), $hotels, 1, max(0, $net), 0, 0);
    }

    /**
     * Pro-rata difference for upgrading for the rest of the current period:
     * (new net price - current net price) x share of the period left.
     */
    public function upgradeDifference(Quote $current, Quote $upgraded, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $length = max(1, $periodStart->diffInSeconds($periodEnd));
        $left = max(0, $now->diffInSeconds($periodEnd, false));

        return (int) round(max(0, $upgraded->net() - $current->net()) * min(1, $left / $length));
    }

    public function discountPercent(Plan $plan, int $hotels): int
    {
        $percent = 0;

        foreach ($this->catalog->discounts() as $discount) {
            if (($discount['plan'] ?? null) === $plan->value && $hotels >= (int) ($discount['min_hotels'] ?? PHP_INT_MAX)) {
                $percent = max($percent, (int) ($discount['percent'] ?? 0));
            }
        }

        return $percent;
    }

    public function taxRate(): float
    {
        return max(0, (float) config('citevanta.billing.tax.rate', 0));
    }

    public function taxIncluded(): bool
    {
        return (bool) config('citevanta.billing.tax.prices_include_tax', true);
    }

    private function withTax(string $currency, int $unit, int $hotels, int $months, int $subtotal, int $percent, int $discount): Quote
    {
        $net = $subtotal - $discount;
        $rate = $this->taxRate();
        $included = $this->taxIncluded();

        if ($rate <= 0) {
            [$tax, $total] = [0, $net];
        } elseif ($included) {
            [$tax, $total] = [(int) round($net - $net / (1 + $rate / 100)), $net];
        } else {
            $tax = (int) round($net * $rate / 100);
            $total = $net + $tax;
        }

        return new Quote($currency, $unit, $hotels, $months, $subtotal, $percent, $discount, $rate, $included, $tax, $total);
    }
}
