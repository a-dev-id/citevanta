<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('domain')->index();
            $table->string('title', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citations');
    }
};
