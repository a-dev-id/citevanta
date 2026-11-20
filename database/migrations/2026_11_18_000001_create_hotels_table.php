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
            $table->string('name');
            $table->string('city');
            $table->string('country');
            $table->string('website')->nullable();
            $table->string('hotel_type')->nullable();
            $table->timestamps();

            $table->unique(['name', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
