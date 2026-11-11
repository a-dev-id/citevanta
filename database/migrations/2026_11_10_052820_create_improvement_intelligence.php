<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('improvements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->string('detection_key');
            $table->string('title');
            $table->string('category');
            $table->string('priority');
            $table->string('confidence');
            $table->text('explanation');
            $table->json('evidence');
            $table->json('actions');
            $table->string('verified_target_url', 2048)->nullable();
            $table->string('target_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('new');
            $table->boolean('active')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();
            $table->unique(['hotel_id', 'detection_key']);
        });
        Schema::create('improvement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('improvement_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_provider_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_id')->unique();
            $table->string('status');
            $table->string('model');
            $table->json('evidence_snapshot');
            $table->json('request_settings');
            $table->json('raw_metadata')->nullable();
            $table->json('usage')->nullable();
            $table->json('selection')->nullable();
            $table->text('text')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();
        });
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('test_run_id')->nullable()->change();
            $table->foreignId('improvement_explanation_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('improvement_explanations')->exists()) {
            throw new RuntimeException('Archive explanation and accounting records before rolling back this migration.');
        }
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('improvement_explanation_id');
        });
        Schema::dropIfExists('improvement_explanations');
        Schema::dropIfExists('improvement_events');
        Schema::dropIfExists('improvements');
    }
};
