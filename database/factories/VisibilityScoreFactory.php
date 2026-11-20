<?php

namespace Database\Factories;

use App\Models\ScanRun;
use App\Models\VisibilityScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisibilityScore>
 */
class VisibilityScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $answers = 20;
        $mentions = fake()->numberBetween(0, $answers);

        return [
            'scan_run_id' => ScanRun::factory(),
            'hotel_id' => fn (array $attributes) => ScanRun::find($attributes['scan_run_id'])->hotel_id,
            'engine' => 'openai',
            'persona' => null,
            'mentions' => $mentions,
            'answers' => $answers,
            'visibility_pct' => round($mentions / $answers * 100, 1),
            'avg_position' => $mentions ? 2.0 : null,
            'positive' => (int) floor($mentions / 2),
            'neutral' => (int) ceil($mentions / 2),
            'negative' => 0,
        ];
    }
}
