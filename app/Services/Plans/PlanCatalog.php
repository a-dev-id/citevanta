<?php

namespace App\Services\Plans;

use App\Enums\Plan;
use App\Services\Dashboard\DashboardService;
use App\Services\Engines\EngineManager;

/**
 * The paid plans as the website shows them: prices (per hotel per month,
 * USD and IDR), the annual price, what each plan includes, and the volume
 * discounts. Read from config/citevanta.php (plans + pricing), so the
 * pricing page, JSON-LD offers, llms.txt and billing all agree.
 */
final class PlanCatalog
{
    /** Plans sold on the website (the trial uses Starter limits). */
    public const PAID = [Plan::Starter, Plan::Pro];

    /**
     * @return list<array{key: string, name: string, monthly: array{usd: ?int, idr: ?int}, annual: array{usd: ?int, idr: ?int}, limits: PlanLimits, engines: list<string>}>
     */
    public function plans(): array
    {
        return array_map(function (Plan $plan): array {
            $monthly = $this->monthly($plan);

            return [
                'key' => $plan->value,
                'name' => $plan->label(),
                'monthly' => $monthly,
                'annual' => array_map(fn (?int $price) => $price === null ? null : $price * $this->annualMonths(), $monthly),
                'limits' => PlanLimits::for($plan),
                'engines' => array_map(
                    fn (string $engine) => DashboardService::engineLabel($engine),
                    array_values(array_intersect(EngineManager::IMPLEMENTED, PlanLimits::for($plan)->engines)),
                ),
            ];
        }, self::PAID);
    }

    /**
     * @return array{usd: ?int, idr: ?int}
     */
    public function monthly(Plan $plan): array
    {
        $prices = (array) config("citevanta.pricing.prices.{$plan->value}", []);

        return [
            'usd' => isset($prices['usd']) ? (int) $prices['usd'] : null,
            'idr' => isset($prices['idr']) ? (int) $prices['idr'] : null,
        ];
    }

    public function annualMonths(): int
    {
        return max(1, (int) config('citevanta.pricing.annual_months', 10));
    }

    /** IDR is shown only once every paid plan has an IDR price. */
    public function hasIdr(): bool
    {
        return collect(self::PAID)->every(fn (Plan $plan) => $this->monthly($plan)['idr'] !== null);
    }

    /**
     * @return array<string, array{plan: string, min_hotels: int, percent: int}>
     */
    public function discounts(): array
    {
        return (array) config('citevanta.pricing.discounts', []);
    }

    public function trialDays(): int
    {
        return (int) config('citevanta.trial_days', 7);
    }

    public static function format(?int $amount, string $currency): string
    {
        if ($amount === null) {
            return '—';
        }

        return $currency === 'idr' ? 'Rp '.number_format($amount, 0, ',', '.') : '$'.number_format($amount);
    }
}
