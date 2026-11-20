<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free trial cost and abuse protection (see App\Services\Trials).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('signup_ip_hash', 64)->nullable()->index()->after('weekly_report_sent_at');
            $table->boolean('trial_approval_required')->default(false)->after('signup_ip_hash');
            $table->timestamp('trial_approved_at')->nullable()->after('trial_approval_required');
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            // Why a "waiting" scan has not started: verification, approval or budget.
            $table->string('hold_reason', 20)->nullable()->after('status');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->foreignId('source_audit_id')->nullable()->after('is_audit')->constrained('audits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_audit_id');
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            $table->dropColumn('hold_reason');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['signup_ip_hash']);
            $table->dropColumn(['signup_ip_hash', 'trial_approval_required', 'trial_approved_at']);
        });
    }
};
