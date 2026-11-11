<?php

use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiProvider;
use App\Services\PaidExecutionGate;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = realpath($argv[1] ?? '');
if (getenv('PAID_GATE_CONCURRENCY_TEST') !== '1' || ! $database || dirname($database) !== realpath(storage_path('framework')) || ! preg_match('/^paid-gate-concurrency-[a-f0-9-]+\.sqlite$/D', basename($database))) {
    throw new RuntimeException('A disposable SQLite concurrency fixture is required.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'intelligence.model' => 'gpt-6-luna', 'intelligence.openai_key' => 'invalid-concurrency-fixture', 'spending.execution_enabled' => true, 'spending.automatic_organization_limits' => false, 'cache.default' => 'array']);
DB::purge('sqlite');
DB::statement('PRAGMA busy_timeout = 10000');
Http::preventStrayRequests();
$requests = 0;
Http::fake(function () use (&$requests) {
    $requests++;
    usleep(500000);

    return Http::response(['id' => 'resp_mock_concurrent', 'status' => 'completed', 'output' => []]);
});
$run = TestRun::withoutGlobalScopes()->findOrFail((int) $argv[2]);
app(TenantContext::class)->set(1, 1, User::findOrFail($run->user_id));
while (microtime(true) < (float) $argv[3]) {
    usleep(10000);
}
try {
    app(PaidExecutionGate::class)->forSearch($run, fn () => app(OpenAiProvider::class)->execute($run->request_settings));
    $outcome = 'allowed';
} catch (Throwable) {
    $outcome = 'blocked';
}
echo json_encode(['outcome' => $outcome, 'mocked_requests' => $requests], JSON_THROW_ON_ERROR);
