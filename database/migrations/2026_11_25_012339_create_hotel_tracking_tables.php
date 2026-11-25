<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The website tracking script (Private plan): one public key and settings per hotel, and the events the
     * script sends. Events hold no IP address, no user agent and no query string: only the platform, the page
     * path and (for booking clicks) the stay dates and guests.
     */
    public function up(): void
    {
        Schema::create('hotel_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('public_key', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('installed')->nullable();
            $table->string('last_check_result', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('platform', 40)->nullable();
            $table->string('path', 300)->default('');
            $table->date('checkin')->nullable();
            $table->date('checkout')->nullable();
            $table->unsignedSmallInteger('nights')->nullable();
            $table->unsignedTinyInteger('adults')->nullable();
            $table->unsignedTinyInteger('children')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['hotel_id', 'occurred_at']);
            $table->index(['hotel_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('hotel_tracking');
    }
};
