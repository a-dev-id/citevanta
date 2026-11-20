<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription state on the team (see App\Services\Billing\BillingService).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('subscription_status', 20)->default('trialing')->after('trial_ends_at');
            $table->string('billing_interval', 10)->nullable()->after('subscription_status');
            $table->string('currency', 3)->nullable()->after('billing_interval');
            $table->unsignedSmallInteger('hotel_quantity')->default(1)->after('currency');
            $table->timestamp('current_period_starts_at')->nullable()->after('hotel_quantity');
            $table->timestamp('current_period_ends_at')->nullable()->after('current_period_starts_at');
            $table->timestamp('grace_ends_at')->nullable()->after('current_period_ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('grace_ends_at');
            $table->boolean('is_comped')->default(false)->after('cancel_at_period_end');
            // Changes that apply at the next renewal (downgrade, interval, hotels).
            $table->string('next_plan', 20)->nullable()->after('is_comped');
            $table->string('next_interval', 10)->nullable()->after('next_plan');
            $table->unsignedSmallInteger('next_quantity')->nullable()->after('next_interval');
            $table->string('payment_method', 20)->default('manual')->after('next_quantity');
            // Automatic renewal runs at a provider (Xendit recurring plan).
            $table->string('billing_provider', 20)->nullable()->after('payment_method');
            $table->string('provider_subscription_id')->nullable()->index()->after('billing_provider');
            $table->timestamp('trial_ending_notified_at')->nullable()->after('provider_subscription_id');
        });

        // Teams created before billing: paid plans keep running without an end date.
        DB::table('teams')->where('plan', '!=', 'trial')->update(['subscription_status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['provider_subscription_id']);
            $table->dropColumn([
                'subscription_status', 'billing_interval', 'currency', 'hotel_quantity', 'current_period_starts_at',
                'current_period_ends_at', 'grace_ends_at', 'cancel_at_period_end', 'is_comped', 'next_plan',
                'next_interval', 'next_quantity', 'payment_method', 'billing_provider', 'provider_subscription_id',
                'trial_ending_notified_at',
            ]);
        });
    }
};
