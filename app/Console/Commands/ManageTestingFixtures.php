<?php

namespace App\Console\Commands;

use Database\Seeders\LocalTestingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ManageTestingFixtures extends Command
{
    protected $signature = 'testing:fixtures {action=seed : seed, reset, or remove} {--confirm-test-fixtures : Confirm removal of dedicated fixtures only}';

    protected $description = 'Manage isolated local demonstration organizations without provider requests';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Local/testing environments only.');

            return self::FAILURE;
        }
        $action = $this->argument('action');
        if (! in_array($action, ['seed', 'reset', 'remove'], true)) {
            return self::INVALID;
        }
        if ($action !== 'seed' && ! $this->option('confirm-test-fixtures')) {
            $this->error('Use --confirm-test-fixtures for reset/removal.');

            return self::FAILURE;
        }
        if ($action !== 'seed') {
            DB::transaction(function () {
                $ids = DB::table('organizations')->where('is_test_fixture', true)->whereIn('slug', array_map(fn ($plan) => 'citevanta-test-'.$plan, ['free', 'starter', 'pro', 'agency']))->pluck('id');
                DB::table('batch_items')->whereIn('organization_id', $ids)->update(['test_run_id' => null]);
                DB::table('test_runs')->whereIn('organization_id', $ids)->update(['batch_item_id' => null, 'test_batch_id' => null]);
                foreach (['pilot_result_reviews', 'cost_entries', 'credit_entries', 'improvement_events', 'improvement_explanations', 'improvements', 'hotel_assessments', 'hotel_mentions', 'citations', 'api_usages', 'ai_responses', 'test_runs', 'batch_items', 'test_batches', 'plan_changes', 'credit_periods', 'search_prompts', 'market_scenarios', 'hotels', 'monitored_brands', 'organization_user'] as $table) {
                    DB::table($table)->whereIn('organization_id', $ids)->delete();
                }
                DB::table('organizations')->whereIn('id', $ids)->delete();
                foreach (DB::table('users')->where('is_test_fixture', true)->whereIn('email', LocalTestingSeeder::EMAILS)->get() as $user) {
                    $referenced = collect(['plan_changes' => 'user_id', 'budget_months' => 'confirmed_by', 'pilot_result_reviews' => 'reviewed_by', 'hotel_assessments' => 'reviewed_by', 'citations' => 'reviewed_by', 'improvement_events' => 'user_id', 'improvement_explanations' => 'user_id', 'test_batches' => 'user_id'])->contains(fn ($column, $table) => DB::table($table)->where($column, $user->id)->exists());
                    if (! $referenced && ! DB::table('organization_user')->where('user_id', $user->id)->exists() && ! DB::table('organizations')->where('owner_id', $user->id)->exists() && ! DB::table('test_runs')->where('user_id', $user->id)->exists()) {
                        DB::table('sessions')->where('user_id', $user->id)->delete();
                        DB::table('users')->where('id', $user->id)->delete();
                    }
                }
            });
            $path = config('local_testing.credentials_path', storage_path('app/private/testing-credentials.json'));
            if (File::exists($path)) {
                $saved = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
                $remaining = DB::table('users')->where('is_test_fixture', true)->whereIn('email', LocalTestingSeeder::EMAILS)->pluck('email')->all();
                $retained = array_intersect_key($saved, array_flip($remaining));
                if ($retained) {
                    File::put($path, json_encode($retained, JSON_PRETTY_PRINT));
                } else {
                    File::delete($path);
                }
            }
        }
        if ($action !== 'remove') {
            app(LocalTestingSeeder::class)->run();
        }
        $this->info('Dedicated testing fixtures '.$action.' complete. No provider requests. Credentials: storage/app/private/testing-credentials.json');

        return self::SUCCESS;
    }
}
