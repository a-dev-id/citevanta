<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => 'organization-'.Str::uuid(), 'status' => 'active', 'owner_id' => User::factory(), 'plan_key' => 'free', 'monthly_limit_micros' => 0];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn ($organization) => $organization->members()->attach($organization->owner_id, ['role' => 'owner']));
    }
}
