<?php

use App\Enums\Persona;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->enum('persona', Persona::values());
            $table->string('language', 10)->default('en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};
