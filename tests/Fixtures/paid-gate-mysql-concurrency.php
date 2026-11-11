<?php

use App\Models\Organization;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\OpenAiRequestException;
use App\Services\CostControl;
use App\Services\PaidExecutionGate;
use App\Services\PilotStudy;
use App\Services\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$testSchema = 'citevanta_paid_gate_mysql_test';
if (getenv('PAID_GATE_MYSQL_TEST') !== '1' || ! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== $testSchema || config('database.connections.mysql.url') || config('intelligence.openai_key') !== 'invalid-mysql-gate-test-key') {
    throw new RuntimeException('An explicitly isolated MySQL test schema and invalid credential are required.');
}
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('Provider traffic is forbidden in the MySQL gate test.'));
config(['spending.execution_enabled' => true, 'spending.automatic_organization_limits' => false, 'spending.monthly_limit_micros' => 2000000, 'intelligence.model' => 'gpt-6-luna', 'cache.default' => 'array']);

if (($argv[1] ?? null) === 'worker') {
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $run = TestRun::withoutGlobalScopes()->findOrFail((int) $argv[2]);
    app(TenantContext::class)->set(1, 1, User::findOrFail($run->user_id));
    $lockQueryMs = null;
    DB::listen(function (QueryExecuted $query) use (&$lockQueryMs): void {
        if (str_contains($query->sql, 'paid_execution_authorizations') && str_contains(strtolower($query->sql), 'for update')) {
            $lockQueryMs = $query->time;
            usleep(750000);
        }
    });
    while (microtime(true) < (float) $argv[3]) {
        usleep(10000);
    }
    try {
        $gate = app(PaidExecutionGate::class);
        $gate->forSearch($run, function () use ($gate, $run): array {
            $gate->consume($run->request_settings);

            return [];
        });
        $outcome = 'consumed';
    } catch (OpenAiRequestException $exception) {
        if (($exception->diagnostics['error']['code'] ?? null) !== 'paid_execution_denied') {
            throw $exception;
        }
        $outcome = 'denied';
    }
    Http::assertNothingSent();
    echo json_encode(['connection_id' => $connectionId, 'outcome' => $outcome, 'grant_lock_query_ms' => $lockQueryMs, 'provider_requests' => 0], JSON_THROW_ON_ERROR);
    exit;
}

$mysql = config('database.connections.mysql');
$server = new PDO('mysql:host='.$mysql['host'].';port='.$mysql['port'].';charset=utf8mb4', $mysql['username'], $mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$check = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$check->execute([$testSchema]);
if ($check->fetchColumn() !== false) {
    throw new RuntimeException('Refusing to overwrite a pre-existing test schema.');
}
$created = false;
$workers = [];
try {
    $server->exec('CREATE DATABASE `citevanta_paid_gate_mysql_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]);
    $owner = User::factory()->create(['email' => 'mysql-gate-owner@example.test', 'is_platform_admin' => true]);
    $organization = Organization::findOrFail(1);
    $organization->members()->attach($owner->id, ['role' => 'owner']);
    $organization->forceFill(['owner_id' => $owner->id, 'plan_key' => 'agency', 'monthly_limit_micros' => 1500000])->save();
    app(TenantContext::class)->set(1, 1, $owner);
    app(CostControl::class)->settings()->update(['monthly_limit_micros' => 2000000]);
    $study = app(PilotStudy::class);
    $batch = $study->prepare($owner);
    if ($batch->id !== 1) {
        throw new RuntimeException('The isolated pilot must have the exact gate target ID.');
    }
    $study->enable($batch, $owner, 650000, $study->fingerprint($batch));
    DB::table('paid_execution_authorizations')->where('id', 1)->update(['status' => 'active', 'approved_by' => $owner->id, 'activated_at' => now(), 'expires_at' => now()->addMinutes(5), 'pilot_fingerprint' => $study->fingerprint($batch->fresh())]);
    $item = $batch->items()->firstOrFail();
    $submission = (string) Str::uuid();
    $item->update(['status' => 'processing', 'attempts' => 1, 'submission_id' => $submission, 'claimed_at' => now()]);
    $settings = ['model' => 'gpt-6-luna', 'input' => $item->prompt_snapshot['text'], 'instructions' => 'Answer in English. Use web search to answer the query. Clearly distinguish recommendations from businesses only mentioned for context. Cite sources.', 'tools' => [['type' => 'web_search', 'user_location' => ['type' => 'approximate', 'country' => 'AU']]], 'tool_choice' => 'required', 'max_output_tokens' => 2500, 'max_tool_calls' => 1, 'store' => false];
    $run = app(CostControl::class)->reserve(['user_id' => $owner->id, 'ai_provider_id' => $batch->ai_provider_id, 'search_prompt_id' => $item->search_prompt_id, 'market_scenario_id' => $item->market_scenario_id, 'test_batch_id' => 1, 'batch_item_id' => $item->id, 'submission_id' => $submission, 'model' => 'gpt-6-luna', 'prompt_version' => 1, 'prompt_text' => $settings['input'], 'market_snapshot' => $item->market_snapshot, 'request_settings' => $settings, 'status' => 'running', 'repetition' => 1, 'executed_at' => now()], $item);
    $start = (string) (microtime(true) + 3);
    foreach ([1, 2] as $worker) {
        $process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', (string) $run->id, $start], base_path());
        $process->setTimeout(30);
        $process->start();
        $workers[] = $process;
    }
    $results = [];
    foreach ($workers as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('MySQL worker failed: '.$process->getErrorOutput());
        }
        $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
    $outcomes = array_column($results, 'outcome');
    sort($outcomes);
    $grant = DB::table('paid_execution_authorizations')->first();
    $blocked = collect($results)->firstWhere('outcome', 'denied');
    $engine = DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paid_execution_authorizations'")->ENGINE;
    if ($outcomes !== ['consumed', 'denied'] || count(array_unique(array_column($results, 'connection_id'))) !== 2 || $grant->status !== 'consumed' || (int) $grant->consumed_test_run_id !== $run->id || DB::table('paid_execution_authorizations')->whereNotNull('consumed_at')->count() !== 1 || ($blocked['grant_lock_query_ms'] ?? 0) < 400 || $engine !== 'InnoDB') {
        throw new RuntimeException('MySQL exclusivity or real lock contention was not proven: '.json_encode($results));
    }
    Http::assertNothingSent();
    $report = ['result' => 'passed', 'engine' => 'MySQL / InnoDB', 'workers' => $results, 'consumed_authorizations' => 1, 'provider_requests' => 0, 'provider_adapter_invoked' => false, 'production_database_modified' => false, 'test_schema_cleanup' => 'dropped in finally'];
    file_put_contents(storage_path('app/paid-gate-mysql-concurrency.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach ($workers as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    DB::disconnect('mysql');
    if ($created) {
        $server->exec('DROP DATABASE `citevanta_paid_gate_mysql_test`');
    }
}
