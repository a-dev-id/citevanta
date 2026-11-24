<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The result of the self-check after a sync: stored against live GA4 for the last 28 days.
     * Nothing is deleted.
     */
    public function up(): void
    {
        if (Schema::hasTable('hotel_google_links') && ! Schema::hasColumn('hotel_google_links', 'data_check')) {
            Schema::table('hotel_google_links', function (Blueprint $table) {
                $table->json('data_check')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hotel_google_links', 'data_check')) {
            Schema::table('hotel_google_links', function (Blueprint $table) {
                $table->dropColumn('data_check');
            });
        }
    }
};
