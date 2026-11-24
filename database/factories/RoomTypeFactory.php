<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoomType> */
class RoomTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'name' => fake()->unique()->randomElement(['Deluxe Room', 'Garden Villa', 'Pool Suite', 'Family Room', 'Ocean Villa']),
            'description' => null,
            'position' => 0,
        ];
    }
}
