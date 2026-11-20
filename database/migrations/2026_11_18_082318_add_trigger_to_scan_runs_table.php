<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scan_runs', function (Blueprint $table) {
            $table->string('trigger', 20)->default('cli')->after('model');
            $table->foreignId('triggered_by')->nullable()->after('trigger')->constrained('users')->nullOnDelete();

            $table->index(['hotel_id', 'trigger', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'trigger', 'created_at']);
            $table->dropConstrainedForeignId('triggered_by');
            $table->dropColumn('trigger');
        });
    }
};
