<?php

namespace Database\Factories;

use App\Models\Competitor;
use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Competitor> */
class CompetitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'name' => fake()->unique()->company().' Resort',
            'website' => fake()->optional()->url(),
        ];
    }
}
