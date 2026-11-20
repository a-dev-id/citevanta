<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Models\Hotel;
use App\Models\ScanRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScanRun> */
class ScanRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hotel_id' => Hotel::factory(),
            'engine' => 'openai',
            'model' => 'gpt-5-mini',
            'trigger' => ScanTrigger::Scheduled,
            'status' => ScanStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'cost_usd' => 0,
        ];
    }
}
