<?php

namespace Database\Factories;

use App\Models\Audit;
use App\Services\Audits\AuditService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Audit>
 */
class AuditFactory extends Factory
{
    /**
     * A completed audit (no scan behind it): for report pages and admin lists.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hotel = fake()->unique()->company().' Resort';

        return [
            'token' => Str::random(48),
            'hotel_name' => $hotel,
            'city' => 'Ubud',
            'country' => 'Indonesia',
            'website' => null,
            'email' => fake()->unique()->safeEmail(),
            'consent' => false,
            'locale' => 'en',
            'dedupe_key' => AuditService::dedupeKey($hotel, 'Ubud'),
            'status' => Audit::COMPLETED,
            'questions' => 10,
            'questions_mentioned' => 3,
            'visibility' => 30.0,
            'recommended_instead' => [['name' => 'Bamboo Valley Retreat', 'answers' => 4]],
            'domain_types' => ['ota' => 6, 'review' => 2],
            'insights' => [['key' => 'mentioned', 'params' => ['count' => 3, 'total' => 10]]],
            'cost_usd' => 0.12,
            'finished_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(['status' => Audit::RUNNING, 'questions' => 0, 'questions_mentioned' => 0, 'visibility' => null, 'finished_at' => null]);
    }
}
