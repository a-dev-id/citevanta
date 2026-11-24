<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 6 step 4: synced booking dates (check-in, check-out, nights)
     * for the demand-by-date reports.
     */
    public function up(): void
    {
        Schema::create('ga4_booking_dates_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('row_key', 40);
            $table->string('event_name', 60);
            $table->date('checkin');
            $table->date('checkout')->nullable();
            $table->unsignedSmallInteger('nights')->nullable();
            $table->unsignedInteger('events')->default(0);

            $table->unique(['hotel_id', 'row_key']);
            $table->index(['hotel_id', 'date']);
            $table->index(['hotel_id', 'checkin']);
        });

        Schema::table('hotel_google_links', function (Blueprint $table) {
            $table->timestamp('booking_dates_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_google_links', function (Blueprint $table) {
            $table->dropColumn('booking_dates_synced_at');
        });

        Schema::dropIfExists('ga4_booking_dates_daily');
    }
};