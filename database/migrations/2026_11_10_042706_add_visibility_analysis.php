<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();
            $table->string('name');
            $table->json('aliases');
            $table->json('official_websites');
            $table->text('verification_url');
            $table->timestamp('verified_at');
            $table->timestamps();
        });
        Schema::create('hotel_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->constrained()->restrictOnDelete();
            $table->boolean('mentioned')->default(true);
            $table->boolean('recommended')->default(false)->index();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->text('evidence');
            $table->json('occurrences');
            $table->json('supporting_citation_ids');
            $table->string('rule');
            $table->boolean('manual')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['ai_response_id', 'hotel_id']);
        });
        Schema::table('ai_responses', function (Blueprint $table) {
            $table->string('analysis_fingerprint', 64)->nullable();
            $table->json('unresolved_hotels')->nullable();
            $table->timestamp('analyzed_at')->nullable();
        });
        Schema::table('citations', function (Blueprint $table) {
            $table->string('normalized_domain')->nullable()->index();
            $table->text('normalized_url')->nullable();
            $table->string('classification')->nullable()->index();
            $table->foreignId('hotel_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('manual')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('citations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['normalized_domain', 'normalized_url', 'classification', 'manual', 'review_note']);
        });
        Schema::table('ai_responses', fn (Blueprint $table) => $table->dropColumn(['analysis_fingerprint', 'unresolved_hotels', 'analyzed_at']));
        Schema::dropIfExists('hotel_assessments');
        Schema::dropIfExists('hotels');
    }
};
