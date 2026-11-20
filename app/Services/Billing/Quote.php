<?php

namespace App\Services\Billing;

/**
 * The price of one invoice in minor units (see Money).
 *
 * subtotal - discount = net. Tax is either included in net (prices include
 * tax) or added on top. total is what the customer pays before any bank
 * transfer unique code.
 */
final readonly class Quote
{
    public function __construct(
        public string $currency,
        public int $unitPrice,
        public int $hotels,
        public int $months,
        public int $subtotal,
        public int $discountPercent,
        public int $discount,
        public float $taxRate,
        public bool $taxIncluded,
        public int $tax,
        public int $total,
    ) {}

    public function net(): int
    {
        return $this->subtotal - $this->discount;
    }

    /**
     * @return array<string, int|float|bool|string>
     */
    public function toInvoiceAttributes(): array
    {
        return [
            'currency' => $this->currency,
            'unit_price' => $this->unitPrice,
            'hotels' => $this->hotels,
            'months' => $this->months,
            'subtotal' => $this->subtotal,
            'discount_percent' => $this->discountPercent,
            'discount' => $this->discount,
            'tax_rate' => $this->taxRate,
            'tax_included' => $this->taxIncluded,
            'tax' => $this->tax,
            'total' => $this->total,
        ];
    }
}
