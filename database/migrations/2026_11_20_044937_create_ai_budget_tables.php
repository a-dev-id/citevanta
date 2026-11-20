<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI budget (App\Services\Budget): every AI call's real cost, the alerts
     * already sent this month, paused insights, and the audit waitlist.
     */
    public function up(): void
    {
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('bucket', 10);
            $table->string('kind', 10);
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 100);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedSmallInteger('web_searches')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['bucket', 'created_at']);
            $table->index(['team_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });

        Schema::create('budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7);
            $table->string('bucket', 10);
            $table->unsignedTinyInteger('percent');
            $table->timestamp('created_at')->nullable();

            $table->unique(['month', 'bucket', 'percent']);
        });

        Schema::table('scan_runs', function (Blueprint $table) {
            // Insight calls after the scan were refused by the budget; resumed from Admin > System.
            $table->timestamp('insights_paused_at')->nullable()->after('hold_reason');
        });

        Schema::create('audit_waitlist', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('locale', 5)->default('en');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_waitlist');

        Schema::table('scan_runs', function (Blueprint $table) {
            $table->dropColumn('insights_paused_at');
        });

        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('ai_usages');
    }
};
