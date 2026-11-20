<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 6 steps 1-3: the private plan with the team's own OpenAI key,
     * the Google agent connection, hotel GA4 / Search Console links and the
     * synced daily data.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Per-team limits (any plan; set for "private" in Admin > Teams).
            $table->json('plan_limits')->nullable()->after('plan');
            $table->unsignedBigInteger('private_price_usd')->nullable()->after('plan_limits');
            $table->unsignedBigInteger('private_price_idr')->nullable()->after('private_price_usd');
            // The team's own OpenAI key (encrypted) and its state.
            $table->text('openai_key')->nullable()->after('trial_approved_at');
            $table->string('openai_key_last4', 4)->nullable()->after('openai_key');
            $table->timestamp('openai_key_checked_at')->nullable()->after('openai_key_last4');
            $table->timestamp('openai_key_stopped_at')->nullable()->after('openai_key_checked_at');
            $table->string('openai_key_stop_code', 60)->nullable()->after('openai_key_stopped_at');
            $table->decimal('ai_budget_usd', 8, 2)->default(50)->after('openai_key_stop_code');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            // Paid with the team's own OpenAI key: never counts against our budgets.
            $table->boolean('billed_to_team')->default(false)->after('bucket');
            $table->index(['billed_to_team', 'created_at']);
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->string('booking_engine_domain')->nullable()->after('website');
        });

        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->string('status', 20)->default('ok');
            $table->text('error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('failure_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hotel_google_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('ga4_property', 40)->nullable();
            $table->string('ga4_property_name')->nullable();
            $table->string('ga4_currency', 3)->nullable();
            $table->string('ga4_timezone', 64)->nullable();
            $table->string('gsc_site')->nullable();
            $table->json('date_mapping')->nullable();
            $table->json('health')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('first_synced_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ga4_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('row_key', 40);
            $table->string('source', 190)->default('');
            $table->string('medium', 100)->default('');
            $table->string('country', 100)->default('');
            $table->string('landing_page', 500)->default('');
            $table->string('device', 30)->default('');
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->decimal('revenue', 14, 2)->default(0);

            $table->unique(['hotel_id', 'row_key']);
            $table->index(['hotel_id', 'date']);
        });

        Schema::create('ga4_events_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('event_name', 120);
            $table->unsignedInteger('event_count')->default(0);
            $table->decimal('event_value', 14, 2)->default(0);

            $table->unique(['hotel_id', 'date', 'event_name']);
        });

        Schema::create('gsc_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('row_key', 40);
            $table->string('query', 500)->default('');
            $table->string('page', 500)->default('');
            $table->string('country', 10)->default('');
            $table->string('device', 20)->default('');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 8, 2)->default(0);

            $table->unique(['hotel_id', 'row_key']);
            $table->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_daily');
        Schema::dropIfExists('ga4_events_daily');
        Schema::dropIfExists('ga4_traffic_daily');
        Schema::dropIfExists('hotel_google_links');
        Schema::dropIfExists('google_connections');

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('booking_engine_domain');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropIndex(['billed_to_team', 'created_at']);
            $table->dropColumn('billed_to_team');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'plan_limits', 'private_price_usd', 'private_price_idr', 'openai_key', 'openai_key_last4',
                'openai_key_checked_at', 'openai_key_stopped_at', 'openai_key_stop_code', 'ai_budget_usd',
            ]);
        });
    }
};
