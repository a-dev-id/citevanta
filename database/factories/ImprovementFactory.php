<?php

namespace Database\Factories;

use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ImprovementFactory extends Factory
{
    public function definition(): array
    {
        return ['hotel_id' => app(CurrentWorkspace::class)->hotel()->id, 'detection_key' => 'fixture:'.Str::uuid(), 'title' => 'Review saved visibility evidence', 'category' => 'Search intent coverage', 'priority' => 'low', 'confidence' => 'low', 'explanation' => 'Hypothesis requiring verification.', 'evidence' => [], 'actions' => ['coverage' => 'Review verified information.'], 'status' => 'new', 'active' => true, 'refreshed_at' => now()];
    }
}
