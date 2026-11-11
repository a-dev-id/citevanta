<?php

use App\Models\CreditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\CreditLedger;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

// Test-only harness. Never accepts the application's database as a target.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('CREDIT_CONCURRENCY_TEST') !== '1' || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'citevanta_credit_concurrency_test' || config('intelligence.openai_key') !== 'invalid-credit-test-key') {
    throw new RuntimeException('A dedicated MySQL test database and invalid API key are required.');
}

Http::preventStrayRequests();
config(['plans.credit_weights.openai' => ['*' => 1, 'gpt-4.1-mini' => 4]]);
if (($argv[1] ?? null) === 'worker') {
    $org = Organization::where('slug', 'nandini-jungle')->firstOrFail();
    $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->where('active', true)->first();
    $actor = User::findOrFail($org->owner_id);
    app(TenantContext::class)->set($org->id, $brand->id, $actor);
    while (microtime(true) < (float) $argv[3]) {
        usleep(10000);
    }
    try {
        DB::transaction(function () use ($actor, $argv) {
            app(CreditLedger::class)->reserve('search:'.$argv[2], $actor->id, 'openai', 'gpt-4.1-mini');
            usleep(500000);
        });
        echo 'reserved';
    } catch (ValidationException) {
        echo 'rejected';
    }
    exit;
}
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]);
$org = Organization::where('slug', 'nandini-jungle')->firstOrFail();
$user = User::factory()->create(['email' => 'race@example.test', 'email_verified_at' => now()]);
$org->members()->sync([$user->id => ['role' => 'owner']]);
$org->forceFill(['owner_id' => $user->id, 'plan_key' => 'free'])->save();
$brand = DB::table('monitored_brands')->where('organization_id', $org->id)->first();
app(TenantContext::class)->set($org->id, $brand->id, $user);
app(CreditLedger::class)->period();
$start = (string) (microtime(true) + 3);
$processes = [];
foreach (['race-a', 'race-b'] as $key) {
    $p = new Process([PHP_BINARY, __FILE__, 'worker', $key, $start], base_path());
    $p->setTimeout(20);
    $p->start();
    $processes[] = $p;
}
$out = [];
foreach ($processes as $p) {
    $p->wait();
    if (! $p->isSuccessful()) {
        throw new RuntimeException('Worker failed: '.$p->getErrorOutput());
    }$out[] = trim($p->getOutput());
}
sort($out);
$balance = app(CreditLedger::class)->balance();
if ($out !== ['rejected', 'reserved'] || $balance['reserved'] !== 4 || $balance['remaining'] !== 1 || CreditEntry::count() !== 1) {
    throw new RuntimeException('Concurrent reservation overdraw or duplicate detected.');
}
echo json_encode(['result' => 'passed', 'database' => 'dedicated MySQL test schema', 'workers' => 2, 'outcomes' => $out, 'allowance' => 5, 'reserved' => 4, 'remaining' => 1, 'entries' => 1, 'paid_requests' => 0]).PHP_EOL;
