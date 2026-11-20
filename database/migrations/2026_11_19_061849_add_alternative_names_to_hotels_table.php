<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Other names AI assistants may use for the hotel, e.g. the name written
     * in Chinese, Korean or Japanese. Used when matching answers.
     */
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('alternative_names')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('alternative_names');
        });
    }
};
