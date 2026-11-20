<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What AI assistants say about a hotel that is wrong or outdated, or that
     * the hotel's facts cannot confirm (verdict not_in_facts = "missing fact").
     * One row per distinct claim (claim_key), updated on every scan it is seen.
     */
    public function up(): void
    {
        Schema::create('accuracy_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('claim_key', 191);
            $table->string('fact_key', 100);
            $table->string('ai_claim', 500);
            $table->string('true_value', 1000)->nullable();
            $table->string('verdict', 20);
            $table->string('severity', 10);
            $table->string('quote', 500)->nullable();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->unsignedInteger('occurrences')->default(0);
            $table->json('engines')->nullable();
            $table->json('example_response_ids')->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('auto_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->string('dismissed_reason', 500)->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'claim_key']);
            $table->index(['hotel_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accuracy_issues');
    }
};
