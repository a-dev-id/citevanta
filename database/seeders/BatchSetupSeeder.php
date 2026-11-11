<?php

namespace Database\Seeders;

use App\Models\BatchItem;
use App\Models\MarketScenario;
use App\Models\SpendingSetting;
use Illuminate\Database\Seeder;

class BatchSetupSeeder extends Seeder
{
    public function run(): void
    {
        SpendingSetting::firstOrCreate(['id' => 1], ['monthly_limit_micros' => config('spending.monthly_limit_micros'), 'batch_request_limit' => config('spending.batch_request_limit'), 'safety_percent' => config('spending.safety_percent')]);
        $unused = MarketScenario::where('name', 'Indonesia / Indonesian')->where('country', 'ID')->where('language', 'Indonesian')->first();
        if ($unused && ! MarketScenario::where('name', 'Indonesia / English')->exists() && ! $unused->runs()->exists() && ! BatchItem::where('market_scenario_id', $unused->id)->exists()) {
            $unused->update(['name' => 'Indonesia / English', 'language' => 'English']);
        }
        foreach ([['Australia', 'AU', 'English'], ['United States', 'US', 'English'], ['United Kingdom', 'GB', 'English'], ['Indonesia', 'ID', 'English']] as [$name,$country,$language]) {
            MarketScenario::firstOrCreate(['name' => $name.' / '.$language], ['country' => $country, 'language' => $language, 'search_settings' => ['type' => 'approximate', 'country' => $country]]);
        }
    }
}
