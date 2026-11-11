<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use App\Models\Hotel;
use App\Models\MarketScenario;
use App\Models\Organization;
use App\Models\SearchPrompt;
use App\Models\TestBatch;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Ai\SimulatedProvider;
use App\Services\CreditLedger;
use App\Services\PlanCatalog;
use App\Services\TenantContext;
use App\Services\VisibilityAnalysis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class LocalTestingSeeder extends Seeder
{
    public const EMAILS = ['platform-admin@example.test', 'free@example.test', 'starter@example.test', 'starter-admin@example.test', 'pro@example.test', 'pro-admin@example.test', 'pro-viewer@example.test', 'agency@example.test', 'agency-admin@example.test', 'agency-viewer@example.test'];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Testing fixtures are restricted to local/testing environments.');
        }
        AiProvider::firstOrCreate(['identifier' => 'simulated'], ['name' => 'Simulated monitoring (local testing)', 'enabled' => true, 'model_configuration' => ['model' => SimulatedProvider::MODEL]]);
        $path = config('local_testing.credentials_path', storage_path('app/private/testing-credentials.json'));
        $credentials = File::exists($path) ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) : [];
        DB::transaction(function () use (&$credentials) {
            foreach (self::EMAILS as $email) {
                $existing = User::where('email', $email)->first();
                if ($existing && ! $existing->is_test_fixture) {
                    throw new \RuntimeException('A requested testing email belongs to an unrelated account. No accounts changed.');
                }
            }
            $platform = $this->account('Platform Administrator', 'platform-admin@example.test', 'Platform', 'Super Admin', $credentials);
            $platform->forceFill(['is_platform_admin' => true])->save();
            foreach (['free', 'starter', 'pro', 'agency'] as $plan) {
                $slug = 'citevanta-test-'.$plan;
                $organization = Organization::where('slug', $slug)->first();
                if ($organization && ! $organization->is_test_fixture) {
                    throw new \RuntimeException('A testing organization slug belongs to unrelated data.');
                }
                $owner = $this->account(ucfirst($plan).' Owner', $plan.'@example.test', ucfirst($plan), 'Owner', $credentials);
                if (! $organization) {
                    $organization = new Organization(['name' => 'CiteVanta Test — '.ucfirst($plan), 'slug' => $slug]);
                    $organization->forceFill(['owner_id' => $owner->id, 'plan_key' => $plan, 'status' => 'active', 'monthly_limit_micros' => 0, 'is_test_fixture' => true])->save();
                    $organization->members()->attach($owner->id, ['role' => 'owner']);
                }
                if ($plan !== 'free') {
                    $admin = $this->account(ucfirst($plan).' Admin', $plan.'-admin@example.test', ucfirst($plan), 'Administrator', $credentials);
                    $organization->members()->syncWithoutDetaching([$admin->id => ['role' => 'administrator']]);
                }
                if (in_array($plan, ['pro', 'agency'], true)) {
                    $viewer = $this->account(ucfirst($plan).' Viewer', $plan.'-viewer@example.test', ucfirst($plan), 'Viewer', $credentials);
                    $organization->members()->syncWithoutDetaching([$viewer->id => ['role' => 'viewer']]);
                }
                $brand = DB::table('monitored_brands')->where('organization_id', $organization->id)->where('slug', 'nandini-demo')->first();
                $brandId = $brand?->id ?? DB::table('monitored_brands')->insertGetId(['organization_id' => $organization->id, 'name' => 'Nandini Jungle by Hanging Gardens', 'short_name' => 'Nandini Jungle', 'slug' => 'nandini-demo', 'official_website' => 'https://nandinibali.com', 'industry' => 'Hospitality', 'description' => 'Luxury Resort · Ubud, Bali, Indonesia. SIMULATED TEST DATA.', 'country' => 'ID', 'aliases' => json_encode(['Nandini Jungle', 'Nandini Jungle Resort']), 'verified_pages' => '[]', 'active' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                app(TenantContext::class)->run($organization->id, $brandId, function () use ($owner) {
                    $this->workspace($owner);
                }, $owner);
            }
        });
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
    }

    private function account(string $name, string $email, string $organization, string $role, array &$credentials): User
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $password = bin2hex(random_bytes(18)).'!Aa9';
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['is_test_fixture' => true, 'email_verified_at' => now()])->save();
            $credentials[$email] = ['name' => $name, 'email' => $email, 'password' => $password, 'organization' => $organization, 'role' => $role];
        }
        if (! isset($credentials[$email])) {
            throw new \RuntimeException('A dedicated account is missing its private credentials. Reset unused fixtures or recover the credential document before reseeding.');
        }

        return $user;
    }

    private function workspace(User $owner): void
    {
        Hotel::firstOrCreate(['identifier' => 'nandini-demo'], ['name' => 'Nandini Jungle by Hanging Gardens', 'aliases' => ['Nandini Jungle', 'Nandini Jungle Resort'], 'official_websites' => ['https://nandinibali.com'], 'verification_url' => 'https://nandinibali.com', 'verified_at' => now()]);
        foreach (['Hanging Gardens of Bali', 'Padma Resort Ubud', 'Viceroy Bali', 'Capella Ubud', 'COMO Shambhala Estate'] as $name) {
            Hotel::firstOrCreate(['identifier' => Str::slug($name)], ['name' => $name, 'aliases' => [$name], 'official_websites' => [], 'verification_url' => 'https://fixture.example.test/competitors/'.Str::slug($name), 'verified_at' => now()]);
        }
        $market = MarketScenario::firstOrCreate(['name' => 'Australia / English'], ['country' => 'AU', 'language' => 'English', 'search_settings' => ['type' => 'approximate', 'country' => 'AU']]);
        $questions = [
            'What are the best luxury jungle resorts in Ubud, Bali?',
            'Which resorts in Ubud offer the best rainforest views?',
            'What are the most romantic jungle resorts in Bali?',
            'Where should couples stay for a peaceful luxury escape in Ubud?',
            'What are the best luxury wellness retreats in Ubud?',
            'Which Bali resorts offer an authentic jungle experience?',
            'What are the best honeymoon resorts in Ubud?',
            'Which luxury resorts in Ubud are surrounded by nature?',
            'Where can I find a secluded luxury resort in Bali?',
            'What are the best boutique luxury resorts near Ubud?',
            'Which resorts in Bali offer a unique rainforest experience?',
            'What are the best nature-focused luxury resorts in Bali?',
        ];
        $answers = [
            '1. Nandini Jungle by Hanging Gardens is recommended for its rainforest setting.',
            'Nandini Jungle is mentioned for context only, not recommended.',
            'Padma Resort Ubud is recommended for a relaxing stay.',
            'Nandini Jungle Resort is recommended for couples.',
            'Consider Capella Ubud for a wellness escape.',
            '1. Hanging Gardens of Bali is recommended.\n2. Nandini Jungle by Hanging Gardens is recommended.',
            'Nandini Jungle is recommended for a honeymoon.',
            'Viceroy Bali is recommended for nature views.',
            'COMO Shambhala Estate is recommended for a secluded stay.',
            'Nandini Jungle by Hanging Gardens is recommended as a boutique choice.',
        ];
        $provider = AiProvider::where('identifier', 'openai')->firstOrFail();
        $batch = null;
        if (app(PlanCatalog::class)->allows('campaigns')) {
            $batch = TestBatch::firstOrCreate(['creation_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'demo-campaign:'.app(TenantContext::class)->organizationId)], ['user_id' => $owner->id, 'name' => 'SIMULATED — Monitoring scenarios', 'status' => 'completed_with_errors', 'model' => 'simulated-fixture', 'ai_provider_id' => $provider->id, 'configuration' => ['simulated' => true, 'repetitions' => 1, 'max_attempts' => 1], 'request_count' => 12, 'request_limit' => 12, 'started_at' => now()->subHour(), 'finished_at' => now()]);
        }
        foreach ($questions as $index => $text) {
            $key = 'demo:'.app(TenantContext::class)->organizationId.':'.($index + 1);
            $prompt = SearchPrompt::firstOrCreate(['prompt_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $key)], ['category' => 'Hospitality testing fixture', 'text' => $text, 'version' => 1, 'active' => true]);
            $submission = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $key.':result');
            $item = $batch ? $batch->items()->firstOrCreate(['search_prompt_id' => $prompt->id, 'market_scenario_id' => $market->id, 'repetition' => 1], ['prompt_snapshot' => $prompt->only(['id', 'prompt_id', 'text', 'category', 'version', 'active']), 'market_snapshot' => $market->only(['id', 'name', 'country', 'language', 'search_settings']), 'status' => $index === 10 ? 'failed' : ($index === 11 ? 'interrupted' : 'completed'), 'attempts' => 0]) : null;
            if ($existing = TestRun::where('submission_id', $submission)->first()) {
                if ($item && ! $existing->test_batch_id) {
                    $existing->update(['test_batch_id' => $batch->id, 'batch_item_id' => $item->id]);
                    $item->update(['test_run_id' => $existing->id]);
                }

                continue;
            }
            $status = $index === 10 ? 'failed' : ($index === 11 ? 'interrupted' : 'completed');
            $run = TestRun::create(['is_simulated' => true, 'test_batch_id' => $batch?->id, 'batch_item_id' => $item?->id, 'user_id' => $owner->id, 'ai_provider_id' => $provider->id, 'search_prompt_id' => $prompt->id, 'market_scenario_id' => $market->id, 'submission_id' => $submission, 'model' => 'simulated-fixture', 'prompt_text' => $text, 'prompt_version' => 1, 'market_snapshot' => $market->only(['name', 'country', 'language', 'search_settings']), 'request_settings' => ['simulated' => true], 'status' => $status, 'repetition' => 1, 'executed_at' => now()->subMinutes(12 - $index)]);
            if ($item) {
                $item->update(['test_run_id' => $run->id]);
            }
            $response = $run->response()->create(['text' => isset($answers[$index]) ? str_replace('\n', "\n", $answers[$index]) : null, 'status' => $status, 'raw_metadata' => ['simulated' => true, 'status' => $status, 'output' => $status === 'completed' ? [['type' => 'web_search_call', 'status' => 'completed']] : []], 'error_details' => $status === 'completed' ? null : ($status === 'failed' ? 'Simulated failed search. No provider request was sent.' : 'Simulated uncertain result. No provider request was sent.')]);
            if ($index === 0 || $index === 3 || $index === 6) {
                $response->citations()->create(['url' => 'https://nandinibali.com', 'domain' => 'nandinibali.com', 'title' => 'SIMULATED: official website', 'is_official' => true]);
            } elseif ($status === 'completed') {
                $response->citations()->create(['url' => 'https://travel-review.example.test/resorts', 'domain' => 'travel-review.example.test', 'title' => 'SIMULATED: third-party review', 'is_official' => false]);
            }
            app(VisibilityAnalysis::class)->analyze($response);
        }
        app(CreditLedger::class)->balance();
    }
}
