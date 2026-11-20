<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('website')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
