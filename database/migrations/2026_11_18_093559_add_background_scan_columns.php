<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scan_runs', function (Blueprint $table) {
            $table->string('batch_id', 64)->nullable()->after('triggered_by');
            // The plan saved when the scan starts, so retries know which calls belong to it.
            $table->json('prompt_ids')->nullable()->after('batch_id');
            $table->unsignedTinyInteger('runs_per_prompt')->nullable()->after('prompt_ids');
            $table->unsignedInteger('answers_count')->default(0)->after('finished_at');
            $table->unsignedInteger('failed_count')->default(0)->after('answers_count');
            $table->json('errors')->nullable()->after('failed_count');
            // Domain counts per scan (see ScanFinalizer for why this is JSON, not a table).
            $table->json('citation_summary')->nullable()->after('errors');
        });

        Schema::table('responses', function (Blueprint $table) {
            $table->string('engine', 30)->default('openai')->after('prompt_id');
            $table->string('model', 100)->nullable()->after('engine');

            // One answer per call: makes ScanPromptJob idempotent.
            $table->unique(['scan_run_id', 'prompt_id', 'run_number', 'engine'], 'responses_call_unique');
        });

        Schema::table('response_analyses', function (Blueprint $table) {
            $table->unsignedInteger('tokens_in')->default(0)->after('summary');
            $table->unsignedInteger('tokens_out')->default(0)->after('tokens_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('response_analyses', function (Blueprint $table) {
            $table->dropColumn(['tokens_in', 'tokens_out']);
        });

        Schema::table('responses', function (Blueprint $table) {
            $table->dropUnique('responses_call_unique');
            $table->dropColumn(['engine', 'model']);
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            $table->dropColumn(['batch_id', 'prompt_ids', 'runs_per_prompt', 'answers_count', 'failed_count', 'errors', 'citation_summary']);
        });
    }
};
