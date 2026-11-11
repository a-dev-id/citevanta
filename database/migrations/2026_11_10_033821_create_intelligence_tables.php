<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index();
        });
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('identifier')->unique();
            $table->boolean('enabled')->default(false);
            $table->json('model_configuration');
            $table->timestamps();
        });
        Schema::create('search_prompts', function (Blueprint $table) {
            $table->id();
            $table->uuid('prompt_id')->unique();
            $table->string('category');
            $table->text('text');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('market_scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->char('country', 2);
            $table->string('language');
            $table->json('search_settings');
            $table->timestamps();
        });
        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('search_prompt_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_scenario_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_id')->unique();
            $table->string('model');
            $table->unsignedInteger('prompt_version');
            $table->text('prompt_text');
            $table->json('market_snapshot');
            $table->json('request_settings');
            $table->string('status')->index();
            $table->unsignedInteger('repetition')->default(1);
            $table->timestamp('executed_at')->index();
            $table->timestamps();
        });
        Schema::create('ai_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('text')->nullable();
            $table->json('raw_metadata')->nullable();
            $table->string('api_response_id')->nullable()->index();
            $table->string('status');
            $table->text('error_details')->nullable();
            $table->timestamps();
        });
        Schema::create('citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_response_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('title')->nullable();
            $table->string('domain')->index();
            $table->boolean('is_official')->default(false)->index();
            $table->unsignedInteger('start_index')->nullable();
            $table->unsignedInteger('end_index')->nullable();
            $table->timestamps();
        });
        Schema::create('hotel_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_response_id')->constrained()->cascadeOnDelete();
            $table->string('hotel_name');
            $table->string('normalized_identifier')->index();
            $table->unsignedInteger('position');
            $table->boolean('is_nandini')->index();
            $table->boolean('is_recommendation');
            $table->string('matching_rule');
            $table->timestamps();
        });
        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usages');
        Schema::dropIfExists('hotel_mentions');
        Schema::dropIfExists('citations');
        Schema::dropIfExists('ai_responses');
        Schema::dropIfExists('test_runs');
        Schema::dropIfExists('market_scenarios');
        Schema::dropIfExists('search_prompts');
        Schema::dropIfExists('ai_providers');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
