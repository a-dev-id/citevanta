<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_batches', function (Blueprint $t) {
            $t->string('pilot_key', 80)->nullable();
            $t->json('pilot_snapshot')->nullable();
            $t->unsignedBigInteger('pilot_budget_micros')->nullable();
            $t->foreignId('pilot_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('pilot_enabled_at')->nullable();
            $t->string('pilot_approval_fingerprint', 64)->nullable();
            $t->unique(['organization_id', 'monitored_brand_id', 'pilot_key'], 'pilot_workspace_key');
        });
        Schema::table('test_runs', function (Blueprint $t) {
            $t->unique(['organization_id', 'monitored_brand_id', 'test_batch_id', 'id'], 'pilot_run_batch_identity');
        });
        Schema::create('pilot_result_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('monitored_brand_id');
            $t->unsignedBigInteger('test_batch_id');
            $t->unsignedBigInteger('test_run_id');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->string('decision');
            $t->string('evidence_fingerprint', 64);
            $t->json('checks');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'monitored_brand_id', 'test_run_id', 'id'], 'pilot_review_lookup');
            $t->foreign(['organization_id', 'monitored_brand_id'], 'pilot_review_brand')->references(['organization_id', 'id'])->on('monitored_brands')->restrictOnDelete();
            $t->foreign(['organization_id', 'monitored_brand_id', 'test_batch_id', 'test_run_id'], 'pilot_review_run')->references(['organization_id', 'monitored_brand_id', 'test_batch_id', 'id'])->on('test_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('test_batches')->whereNotNull('pilot_key')->exists() || DB::table('pilot_result_reviews')->exists()) {
            throw new RuntimeException('Preserve pilot approval and quality history; restore a verified backup instead of removing these controls from saved pilots.');
        }
        Schema::dropIfExists('pilot_result_reviews');
        Schema::table('test_runs', fn (Blueprint $t) => $t->dropUnique('pilot_run_batch_identity'));
        Schema::table('test_batches', function (Blueprint $t) {
            $t->dropUnique('pilot_workspace_key');
            $t->dropConstrainedForeignId('pilot_approved_by');
            $t->dropColumn(['pilot_key', 'pilot_snapshot', 'pilot_budget_micros', 'pilot_enabled_at', 'pilot_approval_fingerprint']);
        });
    }
};
