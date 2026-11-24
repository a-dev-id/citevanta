<?php

namespace Database\Factories;

use App\Models\HotelRate;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HotelRate> */
class HotelRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_type_id' => RoomType::factory(),
            'hotel_id' => fn (array $attributes) => RoomType::query()->find($attributes['room_type_id'])->hotel_id,
            'date' => now()->addDays(10)->toDateString(),
            'price' => 1500000,
            'min_stay' => 1,
            'is_available' => true,
            'note' => null,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false, 'price' => null]);
    }
}
