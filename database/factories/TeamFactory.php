<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state: a paid Starter team with no end date.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'plan' => Plan::Starter,
            'trial_ends_at' => null,
            'subscription_status' => SubscriptionStatus::Active,
            'payment_method' => PaymentMethod::Manual,
        ];
    }

    public function plan(Plan $plan): static
    {
        return $this->state(fn () => [
            'plan' => $plan,
            'trial_ends_at' => $plan === Plan::Trial ? now()->addDays(7) : null,
            'subscription_status' => $plan === Plan::Trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
        ]);
    }

    public function expiredTrial(): static
    {
        return $this->state(fn () => [
            'plan' => Plan::Trial,
            'trial_ends_at' => now()->subDay(),
            'subscription_status' => SubscriptionStatus::Trialing,
        ]);
    }

    /**
     * A paid subscription with a period that ends at $endsAt (default in 20 days).
     */
    public function paid(Plan $plan = Plan::Starter, BillingInterval $interval = BillingInterval::Monthly, ?CarbonInterface $endsAt = null, string $currency = 'usd', int $hotels = 1): static
    {
        return $this->state(function () use ($plan, $interval, $endsAt, $currency, $hotels) {
            $ends = $endsAt ?? now()->addDays(20);

            return [
                'plan' => $plan,
                'trial_ends_at' => null,
                'subscription_status' => SubscriptionStatus::Active,
                'billing_interval' => $interval,
                'currency' => $currency,
                'hotel_quantity' => $hotels,
                'current_period_starts_at' => $ends->copy()->subMonthsNoOverflow($interval->months()),
                'current_period_ends_at' => $ends,
            ];
        });
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'plan' => Plan::Starter,
            'subscription_status' => SubscriptionStatus::Expired,
            'billing_interval' => BillingInterval::Monthly,
            'currency' => 'usd',
            'current_period_starts_at' => now()->subDays(45),
            'current_period_ends_at' => now()->subDays(15),
            'grace_ends_at' => now()->subDays(8),
        ]);
    }
}
