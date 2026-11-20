<?php

use App\Enums\Sentiment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('hotel_mentioned');
            $table->unsignedSmallInteger('position')->nullable();
            $table->enum('sentiment', Sentiment::values());
            $table->json('competitors_mentioned');
            $table->json('other_hotels_mentioned');
            $table->text('summary');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_analyses');
    }
};
