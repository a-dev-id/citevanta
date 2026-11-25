<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily totals of all traffic, from a GA4 request with only the date dimension. Adding up the detailed
     * traffic rows can give more sessions than GA4 reports (GA4 is not additive across dimensions), so "all
     * sessions", "all purchases" and "all revenue" come from here.
     */
    public function up(): void
    {
        Schema::create('ga4_totals_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->decimal('revenue', 14, 2)->default(0);
            $table->string('sync_batch', 16)->nullable();

            $table->unique(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_totals_daily');
    }
};
