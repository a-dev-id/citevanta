<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The guest's country on the synced booking dates (Searches by date). Safe on live data:
     *  - it only ADDS a column, with the default '' (an empty country is shown as "Unknown"); no row is changed or deleted;
     *  - the old rows keep their old row_key, which is the table's unique key together with hotel_id; new rows have the
     *    country in their key, so a date is read again per country and a re-sync replaces the old rows by date
     *    (the sync batch logic), never doubling them once its window is done;
     *  - it does nothing when the table or the column is not what it expects, so running it twice is harmless.
     */
    public function up(): void
    {
        if (Schema::hasTable('ga4_booking_dates_daily') && ! Schema::hasColumn('ga4_booking_dates_daily', 'country')) {
            Schema::table('ga4_booking_dates_daily', function (Blueprint $table) {
                $table->string('country', 100)->default('')->after('nights');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ga4_booking_dates_daily', 'country')) {
            Schema::table('ga4_booking_dates_daily', function (Blueprint $table) {
                $table->dropColumn('country');
            });
        }
    }
};
