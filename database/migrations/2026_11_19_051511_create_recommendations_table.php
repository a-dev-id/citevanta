<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Action items for a hotel team, built from scan evidence (RecommendationService).
     * "key" is stable across scans, so a recommendation is updated, never duplicated.
     */
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key', 191);
            $table->string('category', 40);
            $table->string('title', 255);
            $table->text('why');
            $table->json('steps');
            $table->string('where', 255);
            $table->string('effort', 20);
            $table->string('impact', 10);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('status', 20)->default('new');
            // [{type, label, url}] links to prompts, answers, domains, issues.
            $table->json('evidence')->nullable();
            // What "Measured impact" compares: {"type": "persona", "value": "family"} or {"type": "prompt", "value": 12}.
            $table->json('metric')->nullable();
            $table->boolean('ai_written')->default(false);
            // Hash of the rule-built text; when the evidence changes the text is rebuilt (and rewritten by AI).
            $table->string('draft_hash', 40)->nullable();
            $table->foreignId('first_seen_scan_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->foreignId('last_seen_scan_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            // Evidence gone in the latest scan: shown as "Looks done" for the team to confirm.
            $table->timestamp('looks_done_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('dismissed_reason', 500)->nullable();
            $table->timestamp('done_at')->nullable();
            // Last completed scan before it was marked done (the "before" of Measured impact).
            $table->foreignId('done_baseline_scan_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'key']);
            $table->index(['hotel_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
