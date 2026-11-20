<?php

namespace Database\Factories;

use App\Models\Prompt;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScanResponse> */
class ScanResponseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scan_run_id' => ScanRun::factory(),
            'prompt_id' => Prompt::factory(),
            'run_number' => fake()->unique()->numberBetween(1, 60000),
            'answer_text' => fake()->paragraph(),
            'tokens_in' => fake()->numberBetween(100, 2000),
            'tokens_out' => fake()->numberBetween(100, 1000),
            'web_search_calls' => fake()->numberBetween(0, 2),
        ];
    }
}
