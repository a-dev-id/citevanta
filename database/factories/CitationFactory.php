<?php

namespace Database\Factories;

use App\Models\Citation;
use App\Models\ScanResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Citation> */
class CitationFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->randomElement(['tripadvisor.com', 'booking.com', 'expedia.com', 'cntraveler.com']);

        return [
            'response_id' => ScanResponse::factory(),
            'url' => 'https://www.'.$domain.'/'.fake()->slug(),
            'domain' => $domain,
            'title' => fake()->optional()->sentence(),
        ];
    }
}
