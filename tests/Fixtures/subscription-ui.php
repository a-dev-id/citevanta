<?php

use App\Models\Hotel;
use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\User;
use App\Services\TenantContext;
use Database\Seeders\BatchSetupSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'subscription-ui.sqlite' || config('intelligence.openai_key') !== 'ui-mock-key-not-valid') {
    throw new RuntimeException('Isolated SQLite fixture and invalid API key required');
}

$user = User::factory()->create(['name' => 'Credit Customer', 'email' => 'credits@example.test', 'password' => 'UiValidationPassword1234!', 'is_platform_admin' => false, 'email_verified_at' => now()]);
foreach (['free', 'starter', 'pro', 'agency'] as $key) {
    $org = Organization::factory()->create(['name' => 'Credit '.ucfirst($key), 'owner_id' => $user->id, 'plan_key' => $key, 'monthly_limit_micros' => 0]);
    $attrs = MonitoredBrand::factory()->make(['organization_id' => $org->id, 'name' => 'Credit '.ucfirst($key).' Brand'])->getAttributes();
    $brand = DB::table('monitored_brands')->insertGetId($attrs);
    app(TenantContext::class)->run($org->id, $brand, function () {
        $b = app(TenantContext::class)->brand();
        Hotel::create(['identifier' => $b->slug, 'name' => $b->name, 'aliases' => [], 'official_websites' => [$b->official_website], 'verification_url' => $b->official_website, 'verified_at' => now()]);
        app(BatchSetupSeeder::class)->run();
        SearchPrompt::create(['prompt_id' => (string) Str::uuid(), 'category' => 'Discovery', 'text' => 'Best trusted local businesses', 'version' => 1, 'active' => true]);
    });
}
$org = DB::table('organizations')->where('slug', 'nandini-jungle')->first();
$viewer = User::factory()->create(['name' => 'Navigation Viewer', 'email' => 'ui-viewer@example.test', 'password' => 'UiValidationPassword1234!', 'is_platform_admin' => false]);
$viewer->organizations()->attach($org->id, ['role' => 'viewer']);
DB::table('spending_settings')->update(['monthly_limit_micros' => 0]);
echo 'Four isolated customer plan fixtures prepared. No API requests.'.PHP_EOL;
