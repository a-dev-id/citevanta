<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('run_number');
            $table->longText('answer_text');
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedSmallInteger('web_search_calls')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};
