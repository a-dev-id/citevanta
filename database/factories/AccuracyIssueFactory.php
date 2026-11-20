<?php

namespace Database\Factories;

use App\Enums\AccuracyVerdict;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\AccuracyIssue;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccuracyIssue>
 */
class AccuracyIssueFactory extends Factory
{
    /**
     * Define the model's default state: an open, wrong, high-severity claim.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fact = fake()->unique()->randomElement(['Kids club', 'Swimming pool', 'Spa', 'Parking', 'Free Wi-Fi', 'Restaurant', 'Gym']);

        return [
            'hotel_id' => Hotel::factory(),
            'claim_key' => fn (array $attributes) => 'issue|'.str($attributes['fact_key'])->slug().'|'.fake()->unique()->bothify('claim-####'),
            'fact_key' => $fact,
            'ai_claim' => fn (array $attributes) => "The hotel has a {$attributes['fact_key']}.",
            'true_value' => 'No',
            'verdict' => AccuracyVerdict::Wrong,
            'severity' => IssueSeverity::High,
            'quote' => fn (array $attributes) => "Known for its {$attributes['fact_key']}.",
            'occurrences' => 1,
            'engines' => ['openai'],
            'example_response_ids' => [],
            'status' => IssueStatus::Open,
        ];
    }

    public function medium(): static
    {
        return $this->state(['severity' => IssueSeverity::Medium]);
    }

    public function missingFact(): static
    {
        return $this->state(fn (array $attributes) => [
            'claim_key' => 'missing|'.str($attributes['fact_key'])->slug(),
            'verdict' => AccuracyVerdict::NotInFacts,
            'true_value' => null,
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(['status' => IssueStatus::Dismissed, 'dismissed_reason' => 'Not relevant', 'dismissed_at' => now()]);
    }
}
