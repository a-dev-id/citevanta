<?php

namespace Database\Factories;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hotel> */
class HotelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Hotel',
            'city' => fake()->city(),
            'country' => fake()->country(),
            'website' => fake()->optional()->url(),
            'hotel_type' => fake()->optional()->randomElement(['hotel', 'resort', 'boutique hotel', 'villa']),
        ];
    }
}
