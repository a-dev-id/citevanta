<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each user is with each guided tour, so it follows them across devices: which version they saw,
     * whether they finished it or skipped it, and how many times they skipped. Nothing else about their
     * actions is recorded.
     */
    public function up(): void
    {
        Schema::create('user_tours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tour', 40);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 12);
            $table->unsignedTinyInteger('skips')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tours');
    }
};
