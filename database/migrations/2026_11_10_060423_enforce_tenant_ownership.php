<?php

use App\Services\TenantBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (array_keys(TenantBackfill::PARENTS) as $i => $table) {
            if (DB::table($table)->whereNull('organization_id')->orWhereNull('monitored_brand_id')->exists()) {
                throw new RuntimeException('Backfill must complete before constraints.');
            }
            Schema::table($table, function (Blueprint $t) use ($i) {
                $t->unsignedBigInteger('organization_id')->nullable(false)->change();
                $t->unsignedBigInteger('monitored_brand_id')->nullable(false)->change();
                $t->unique(['organization_id', 'monitored_brand_id', 'id'], 'tn_'.$i.'_identity');
                $t->foreign(['organization_id', 'monitored_brand_id'], 'tn_'.$i.'_brand')->references(['organization_id', 'id'])->on('monitored_brands')->restrictOnDelete();
            });
        }
        foreach (TenantBackfill::PARENTS as $i => $parents) {
            $parents = TenantBackfill::parents($i);
            foreach ($parents as $column => $parent) {
                Schema::table($i, function (Blueprint $t) use ($i, $column, $parent, $parents) {
                    $name = 'tp_'.array_search($i, array_keys(TenantBackfill::PARENTS)).'_'.array_search($column, array_keys($parents));
                    $t->foreign(['organization_id', 'monitored_brand_id', $column], $name)->references(['organization_id', 'monitored_brand_id', 'id'])->on($parent)->restrictOnDelete();
                });
            }
        }
        Schema::table('hotels', function (Blueprint $t) {
            $t->dropUnique(['identifier']);
            $t->unique(['organization_id', 'monitored_brand_id', 'identifier'], 'identity_workspace_name');
        });
        Schema::table('market_scenarios', function (Blueprint $t) {
            $t->dropUnique(['name']);
            $t->unique(['organization_id', 'monitored_brand_id', 'name'], 'market_workspace_name');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Tenant isolation constraints must not be removed from a live installation.');
    }
};
