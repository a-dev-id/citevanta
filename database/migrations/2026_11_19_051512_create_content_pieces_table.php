<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copy-ready content generated for a recommendation (one row per version).
     */
    public function up(): void
    {
        Schema::create('content_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('language', 10)->default('en');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('title', 255)->nullable();
            $table->longText('body')->nullable();
            $table->longText('edited_body')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['recommendation_id', 'type', 'language']);
            $table->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pieces');
    }
};
