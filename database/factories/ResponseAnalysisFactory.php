<?php

namespace Database\Factories;

use App\Enums\Sentiment;
use App\Models\ResponseAnalysis;
use App\Models\ScanResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResponseAnalysis> */
class ResponseAnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'response_id' => ScanResponse::factory(),
            'hotel_mentioned' => false,
            'position' => null,
            'sentiment' => Sentiment::NotMentioned,
            'competitors_mentioned' => [],
            'other_hotels_mentioned' => [],
            'summary' => fake()->sentence(),
        ];
    }

    public function mentioned(?int $position = 1, Sentiment $sentiment = Sentiment::Positive): static
    {
        return $this->state(fn () => [
            'hotel_mentioned' => true,
            'position' => $position,
            'sentiment' => $sentiment,
        ]);
    }
}
