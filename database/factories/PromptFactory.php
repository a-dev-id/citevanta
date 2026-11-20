<?php

namespace Database\Factories;

use App\Enums\Persona;
use App\Models\Hotel;
use App\Models\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Prompt> */
class PromptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'text' => 'What is the best hotel in '.fake()->city().'?',
            'persona' => fake()->randomElement(Persona::cases()),
            'language' => 'en',
            'is_active' => true,
        ];
    }
}
