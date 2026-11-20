<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Plan;
use App\Models\Invoice;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * A pending $49 Starter invoice for one month.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'number' => 'INV-'.now()->format('Ym').'-'.str_pad((string) fake()->unique()->numberBetween(1000, 9999), 4, '0', STR_PAD_LEFT),
            'kind' => InvoiceKind::New,
            'status' => InvoiceStatus::Pending,
            'provider' => 'manual',
            'description' => 'Starter plan, 1 hotel, monthly',
            'plan' => Plan::Starter,
            'interval' => BillingInterval::Monthly,
            'hotels' => 1,
            'months' => 1,
            'currency' => 'usd',
            'unit_price' => 4900,
            'subtotal' => 4900,
            'total' => 4900,
            'unique_code' => 37,
            'amount_due' => 4937,
            'due_at' => now()->addDays(7),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
    }
}
