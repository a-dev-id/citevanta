<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * team_id stays nullable so the scan:test command can still scan a hotel
     * that does not belong to any team.
     */
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('star_rating')->nullable()->after('hotel_type');
            $table->json('guest_markets')->nullable()->after('star_rating');
            $table->unsignedTinyInteger('setup_step')->default(1)->after('guest_markets');
            $table->timestamp('setup_completed_at')->nullable()->after('setup_step');

            $table->dropUnique(['name', 'city']);
            $table->unique(['team_id', 'name', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name', 'city']);
            $table->unique(['name', 'city']);
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['star_rating', 'guest_markets', 'setup_step', 'setup_completed_at']);
        });
    }
};
