<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\TrackingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrackingEvent>
 */
class TrackingEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['hotel_id' => Hotel::factory(), 'type' => TrackingEvent::AI_REFERRAL, 'platform' => 'ChatGPT', 'path' => '/', 'occurred_at' => now()];
    }
}
