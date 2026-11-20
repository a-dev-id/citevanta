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
        Schema::create('visibility_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 30);
            $table->string('persona', 20)->nullable(); // null = overall
            $table->unsignedInteger('mentions');
            $table->unsignedInteger('answers');
            $table->decimal('visibility_pct', 5, 1);
            $table->decimal('avg_position', 4, 1)->nullable();
            $table->unsignedInteger('positive');
            $table->unsignedInteger('neutral');
            $table->unsignedInteger('negative');
            $table->timestamps();

            $table->index(['hotel_id', 'engine', 'persona']);
            $table->unique(['scan_run_id', 'engine', 'persona']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visibility_scores');
    }
};
