<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Months one period covers. */
    public function months(): int
    {
        return $this === self::Annual ? 12 : 1;
    }

    /** Months charged for one period (annual = config pricing.annual_months x monthly). */
    public function chargedMonths(): int
    {
        return $this === self::Annual ? max(1, (int) config('citevanta.pricing.annual_months', 10)) : 1;
    }
}
