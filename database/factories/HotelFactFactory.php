<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\HotelFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelFact>
 */
class HotelFactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'key' => fake()->unique()->randomElement(HotelFact::suggestedKeys()),
            'value' => fake()->sentence(3),
            'position' => 0,
        ];
    }
}
