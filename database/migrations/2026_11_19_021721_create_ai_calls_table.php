<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per insight AI call (accuracy, competitor reasons, content...):
     * its tokens and cost (for the scan's cost breakdown), the parsed result,
     * and an idempotency key so a retried job never calls twice.
     */
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('subject', 100);
            $table->string('model', 100);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->json('result')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['scan_run_id', 'purpose', 'subject']);
            $table->index(['hotel_id', 'purpose']);
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            // {"scan": 0.12, "analysis": 0.01, "accuracy": 0.002, "competitor_reasons": 0.003}
            $table->json('cost_breakdown')->nullable()->after('cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table) {
            $table->dropColumn('cost_breakdown');
        });

        Schema::dropIfExists('ai_calls');
    }
};
