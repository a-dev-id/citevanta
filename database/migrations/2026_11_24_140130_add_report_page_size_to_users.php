<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rows per page (10, 50 or 100) a user chose in the Reports tables. Nothing is deleted.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'report_page_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('report_page_size')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'report_page_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('report_page_size');
            });
        }
    }
};
