<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly email report: per-user opt-in (null = default by role) and
     * per-team bookkeeping so each week is handled once.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('weekly_report')->nullable()->after('is_admin');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('weekly_report_week', 10)->nullable()->after('trial_ending_notified_at');
            $table->timestamp('weekly_report_sent_at')->nullable()->after('weekly_report_week');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('weekly_report');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['weekly_report_week', 'weekly_report_sent_at']);
        });
    }
};
