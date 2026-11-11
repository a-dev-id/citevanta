<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Services\TenantContext;
use Database\Seeders\BatchSetupSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MonitoredBrandFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => app(TenantContext::class)->organizationId, 'name' => fake()->company(), 'short_name' => fake()->company(), 'slug' => 'brand-'.Str::uuid(), 'official_website' => 'https://example.test/', 'description' => 'Verified fixture business', 'industry' => 'Professional services', 'country' => 'US', 'aliases' => [], 'verified_pages' => [], 'verified_at' => now(), 'active' => true];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($brand) {
            app(TenantContext::class)->run($brand->organization_id, $brand->id, function () use ($brand) {
                Hotel::create(['identifier' => $brand->slug, 'name' => $brand->name, 'aliases' => $brand->aliases, 'official_websites' => [$brand->official_website], 'verification_url' => $brand->official_website, 'verified_at' => $brand->verified_at]);
                app(BatchSetupSeeder::class)->run();
            });
        });
    }
}
