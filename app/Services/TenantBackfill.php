<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantBackfill
{
    public const PARENTS = [
        'hotels' => [], 'search_prompts' => [], 'market_scenarios' => [],
        'test_batches' => [],
        'batch_items' => ['test_batch_id' => 'test_batches', 'search_prompt_id' => 'search_prompts', 'market_scenario_id' => 'market_scenarios'],
        'test_runs' => ['search_prompt_id' => 'search_prompts', 'market_scenario_id' => 'market_scenarios', 'test_batch_id' => 'test_batches', 'batch_item_id' => 'batch_items'],
        'ai_responses' => ['test_run_id' => 'test_runs'],
        'citations' => ['ai_response_id' => 'ai_responses', 'hotel_id' => 'hotels'],
        'hotel_mentions' => ['ai_response_id' => 'ai_responses'],
        'hotel_assessments' => ['ai_response_id' => 'ai_responses', 'hotel_id' => 'hotels'],
        'api_usages' => ['test_run_id' => 'test_runs'],
        'improvements' => ['hotel_id' => 'hotels'],
        'improvement_events' => ['improvement_id' => 'improvements'],
        'improvement_explanations' => ['improvement_id' => 'improvements'],
        'cost_entries' => ['test_run_id' => 'test_runs', 'improvement_explanation_id' => 'improvement_explanations'],
    ];

    public static function parents(string $table): array
    {
        return (self::PARENTS[$table] ?? ($table === 'pilot_result_reviews' ? ['test_batch_id' => 'test_batches', 'test_run_id' => 'test_runs'] : [])) + ($table === 'batch_items' ? ['test_run_id' => 'test_runs'] : []);
    }

    public function run(): array
    {
        return DB::transaction(function () {
            $now = now();
            $org = DB::table('organizations')->where('slug', 'nandini-jungle')->first();
            $initial = ! $org;
            if (! $org) {
                $owner = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');
                $id = DB::table('organizations')->insertGetId(['name' => 'Nandini Jungle', 'slug' => 'nandini-jungle', 'status' => 'active', 'owner_id' => $owner, 'plan_key' => 'agency', 'monthly_limit_micros' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $org = DB::table('organizations')->find($id);
            }
            foreach ($initial ? DB::table('users')->where('is_admin', true)->get() : [] as $user) {
                DB::table('organization_user')->insertOrIgnore(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $user->id === $org->owner_id ? 'owner' : 'administrator', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => true]);
            }
            $brand = DB::table('monitored_brands')->where('organization_id', $org->id)->where('slug', 'nandini')->first();
            if (! $brand) {
                $hotel = DB::table('hotels')->where('identifier', 'nandini')->first();
                $id = DB::table('monitored_brands')->insertGetId(['organization_id' => $org->id, 'name' => 'Nandini Jungle by Hanging Gardens', 'short_name' => 'Nandini', 'slug' => 'nandini', 'official_website' => 'https://nandinibali.com/', 'description' => 'Monitored property migrated from Phases 1–4.', 'industry' => 'Hospitality', 'country' => 'ID', 'aliases' => $hotel?->aliases ?? json_encode(['Nandini Jungle Resort', 'Nandini Jungle']), 'verified_at' => $hotel?->verified_at ?? $now, 'verified_pages' => json_encode(config('workspace.verified_pages', [])), 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
                $brand = DB::table('monitored_brands')->find($id);
            }
            $counts = [];
            foreach (self::PARENTS as $table => $parents) {
                $before = DB::table($table)->count();
                foreach (DB::table($table)->whereNull('organization_id')->orderBy('id')->cursor() as $row) {
                    $scope = null;
                    foreach ($parents as $key => $parent) {
                        if ($row->$key !== null) {
                            $p = DB::table($parent)->find($row->$key);
                            if (! $p || ! $p->organization_id || ! $p->monitored_brand_id) {
                                throw new RuntimeException('Unresolved ownership in '.$table);
                            }
                            $candidate = [$p->organization_id, $p->monitored_brand_id];
                            if ($scope && $candidate !== $scope) {
                                throw new RuntimeException('Conflicting ownership in '.$table);
                            }
                            $scope = $candidate;
                        }
                    }
                    $scope ??= [$org->id, $brand->id];
                    DB::table($table)->where('id', $row->id)->update(['organization_id' => $scope[0], 'monitored_brand_id' => $scope[1]]);
                }
                if (DB::table($table)->whereNull('organization_id')->orWhereNull('monitored_brand_id')->exists() || DB::table($table)->count() !== $before) {
                    throw new RuntimeException('Backfill count or ownership mismatch: '.$table);
                }
                $counts[$table] = $before;
            }
            foreach (array_keys(self::PARENTS) as $table) {
                foreach (self::parents($table) as $column => $parent) {
                    $invalid = DB::table($table.' as child')->join($parent.' as parent', 'child.'.$column, '=', 'parent.id')->where(fn ($q) => $q->whereColumn('child.organization_id', '!=', 'parent.organization_id')->orWhereColumn('child.monitored_brand_id', '!=', 'parent.monitored_brand_id'))->exists();
                    if ($invalid) {
                        throw new RuntimeException('Cross-workspace relationship in '.$table);
                    }
                }
            }

            return $counts;
        });
    }
}
