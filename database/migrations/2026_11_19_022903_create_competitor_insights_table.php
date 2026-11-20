<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why competitors were recommended instead of the hotel, per scan and prompt.
     *  - competitors: names that beat the hotel in this prompt's answers
     *  - reasons: [{competitor, reason, quote, hotel_has, fact_key}]
     *  - sources: [{domain, competitors, answers}] cited in answers that
     *    recommended a competitor but did not mention the hotel
     */
    public function up(): void
    {
        Schema::create('competitor_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained()->cascadeOnDelete();
            $table->json('competitors');
            $table->json('reasons');
            $table->json('sources');
            $table->string('summary', 1000)->nullable();
            $table->unsignedInteger('wins')->default(0);
            $table->timestamps();

            $table->unique(['scan_run_id', 'prompt_id']);
            $table->index(['hotel_id', 'scan_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_insights');
    }
};
