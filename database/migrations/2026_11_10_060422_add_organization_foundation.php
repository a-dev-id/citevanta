<?php

use App\Services\TenantBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_platform_admin')->default(false));
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->string('slug', 180)->unique();
            $t->string('status')->default('active');
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('plan_key')->default('free');
            $t->unsignedBigInteger('monthly_limit_micros')->default(0);
            $t->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role');
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::create('monitored_brands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('name', 180);
            $t->string('short_name', 180)->nullable();
            $t->string('slug', 180);
            $t->string('official_website', 2048);
            $t->text('description')->nullable();
            $t->string('industry', 100);
            $t->char('country', 2);
            $t->json('aliases');
            $t->timestamp('verified_at');
            $t->json('verified_pages');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
            $t->unique(['organization_id', 'id'], 'brand_org_id');
        });
        foreach (array_keys(TenantBackfill::PARENTS) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('organization_id')->nullable();
                $t->unsignedBigInteger('monitored_brand_id')->nullable();
            });
        }
        app(TenantBackfill::class)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Tenant migration contains preserved customer ownership. Restore a verified backup rather than stripping ownership from live data.');
    }
};
