<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Prompts and competitors are soft deleted: responses and (later)
     * competitor scores keep pointing at them, so scan history survives.
     */
    public function up(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->string('source', 20)->default('custom')->after('language');
            $table->boolean('is_edited')->default(false)->after('source');
            $table->softDeletes();
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['source', 'is_edited']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
