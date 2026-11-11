<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Services\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = DB::table('organizations')->where('slug', 'nandini-jungle')->first();
        $brand = DB::table('monitored_brands')->where('organization_id', $organization->id)->where('slug', 'nandini')->first();
        app(TenantContext::class)->set($organization->id, $brand->id);
        $this->call([HotelSeeder::class, BatchSetupSeeder::class]);
        AiProvider::firstOrCreate(['identifier' => 'openai'], ['name' => 'OpenAI', 'enabled' => true, 'model_configuration' => ['model' => config('intelligence.model')]]);
        SearchPrompt::firstOrCreate(['prompt_id' => 'ce72eddd-3d5d-49b1-8043-8daa93222d27'], ['category' => 'Luxury jungle resorts', 'text' => 'Best luxury jungle resorts in Ubud Bali', 'version' => 1, 'active' => true]);
        MarketScenario::firstOrCreate(['name' => 'Singapore · English'], ['country' => 'SG', 'language' => 'English', 'search_settings' => ['type' => 'approximate', 'country' => 'SG']]);
    }
}
