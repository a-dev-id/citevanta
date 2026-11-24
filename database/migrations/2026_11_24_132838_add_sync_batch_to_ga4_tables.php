<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['ga4_traffic_daily', 'ga4_events_daily', 'ga4_booking_dates_daily'];

    /**
     * Each copy of a date window is stamped with a batch. Old rows stay until the window is complete, then the
     * rows of other batches in that window are removed, so reports never go empty during a re-sync.
     * Nothing is deleted here.
     */
    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'sync_batch')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('sync_batch', 16)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'sync_batch')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('sync_batch');
                });
            }
        }
    }
};
