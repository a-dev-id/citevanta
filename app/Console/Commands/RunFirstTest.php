<?php

namespace App\Console\Commands;

use App\Models\AiProvider;
use App\Models\MarketScenario;
use App\Models\SearchPrompt;
use App\Models\TestRun;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TestExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunFirstTest extends Command
{
    protected $signature = 'intelligence:first-test {--organization=} {--brand=} {--user=} {--confirm-live}';

    protected $description = 'Run one real seeded search test; refuses if any test already exists';

    public function handle(TestExecutor $executor): int
    {
        if (! $this->option('confirm-live') || ! $this->option('organization') || ! $this->option('brand') || ! $this->option('user')) {
            $this->error('Explicit organization, brand, user, and --confirm-live are required. Monthly spending controls still apply.');

            return self::FAILURE;
        }
        $user = User::findOrFail((int) $this->option('user'));

        return app(TenantContext::class)->run((int) $this->option('organization'), (int) $this->option('brand'), fn () => $this->executeFirst($executor, $user), $user);
    }

    private function executeFirst(TestExecutor $executor, User $user): int
    {
        if (! config('intelligence.openai_key')) {
            $this->error('OPENAI_API_KEY is not configured.');

            return self::FAILURE;
        }
        if (TestRun::exists()) {
            $this->error('A test already exists. Use the authenticated interface to review it.');

            return self::FAILURE;
        }
        $run = $executor->execute($user, AiProvider::where('identifier', 'openai')->firstOrFail(), SearchPrompt::where('active', true)->firstOrFail(), MarketScenario::firstOrFail(), config('intelligence.model'), (string) Str::uuid());
        $this->info('Test '.$run->id.': '.$run->status);

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
