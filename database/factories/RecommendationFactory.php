<?php

namespace Database\Factories;

use App\Enums\Effort;
use App\Enums\Impact;
use App\Enums\RecommendationCategory;
use App\Enums\RecommendationStatus;
use App\Models\Hotel;
use App\Models\Recommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    /**
     * Define the model's default state: a new, medium-impact website task.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'key' => 'test:'.fake()->unique()->bothify('rec-####'),
            'category' => RecommendationCategory::WebsiteContent,
            'title' => 'Add a families section to your website',
            'why' => 'AI rarely mentions the hotel for family questions.',
            'steps' => ['Open the home page.', 'Add a section for families.'],
            'where' => 'Your website',
            'effort' => Effort::HalfDay,
            'impact' => Impact::Medium,
            'priority' => 50,
            'status' => RecommendationStatus::New,
            'evidence' => [],
        ];
    }

    public function quickWin(): static
    {
        return $this->state(['impact' => Impact::High, 'effort' => Effort::Hour, 'priority' => 90]);
    }

    public function status(RecommendationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
