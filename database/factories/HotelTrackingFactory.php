<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\HotelTracking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HotelTracking>
 */
class HotelTrackingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['hotel_id' => Hotel::factory(), 'public_key' => HotelTracking::newKey(), 'enabled' => true];
    }
}
