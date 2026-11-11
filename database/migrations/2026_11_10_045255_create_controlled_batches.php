<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('monthly_limit_micros')->default(0);
            $t->unsignedInteger('batch_request_limit')->default(20);
            $t->unsignedInteger('safety_percent')->default(25);
            $t->timestamps();
        });
        Schema::create('budget_months', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7)->unique();
            $t->unsignedBigInteger('opening_micros')->nullable();
            $t->unsignedBigInteger('opening_through_id')->default(0);
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('opening_note')->nullable();
            $t->timestamps();
        });
        Schema::create('test_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('creation_id')->unique();
            $t->string('name');
            $t->string('status')->index();
            $t->string('model');
            $t->foreignId('ai_provider_id')->constrained()->restrictOnDelete();
            $t->json('configuration');
            $t->json('pricing_snapshot')->nullable();
            $t->unsignedInteger('request_count');
            $t->unsignedInteger('request_limit');
            $t->unsignedBigInteger('estimated_micros')->nullable();
            $t->unsignedBigInteger('reserved_estimate_micros')->nullable();
            $t->text('status_note')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('batch_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('test_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('search_prompt_id')->constrained()->restrictOnDelete();
            $t->foreignId('market_scenario_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('repetition');
            $t->json('prompt_snapshot');
            $t->json('market_snapshot');
            $t->string('status')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('submission_id')->nullable()->unique();
            $t->foreignId('test_run_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('available_at')->nullable()->index();
            $t->timestamp('claimed_at')->nullable();
            $t->text('status_note')->nullable();
            $t->timestamps();
            $t->unique(['test_batch_id', 'search_prompt_id', 'market_scenario_id', 'repetition'], 'batch_item_combination');
        });
        Schema::table('test_runs', function (Blueprint $t) {
            $t->foreignId('test_batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('batch_item_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('cost_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('test_run_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('budget_month_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('reserved_micros');
            $t->unsignedBigInteger('committed_micros');
            $t->unsignedBigInteger('usage_cost_micros')->nullable();
            $t->unsignedInteger('safety_percent');
            $t->string('status')->index();
            $t->json('pricing_snapshot');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_entries');
        Schema::table('test_runs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('batch_item_id');
            $t->dropConstrainedForeignId('test_batch_id');
        });
        Schema::dropIfExists('batch_items');
        Schema::dropIfExists('test_batches');
        Schema::dropIfExists('budget_months');
        Schema::dropIfExists('spending_settings');
    }
};
