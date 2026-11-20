<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free AI visibility audits (website lead magnet). Each audit is a lead;
     * a repeat request for the same hotel + city within 7 days points at the
     * earlier audit (source_audit_id) instead of running a new scan.
     */
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            // Hotels created only to run a free audit (no team, never scheduled, hidden from admin lists).
            $table->boolean('is_audit')->default(false)->after('team_id');
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('hotel_name', 120);
            $table->string('city', 120);
            $table->string('country', 120);
            $table->string('website', 190)->nullable();
            $table->string('email', 190);
            $table->boolean('consent')->default(false);
            $table->string('locale', 5)->default('en');
            $table->string('ip_hash', 64)->nullable();
            // Normalised hotel + city, for the 7-day duplicate check.
            $table->string('dedupe_key', 191)->index();
            $table->foreignId('source_audit_id')->nullable()->constrained('audits')->nullOnDelete();
            $table->foreignId('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('questions')->default(0);
            $table->unsignedSmallInteger('questions_mentioned')->default(0);
            $table->decimal('visibility', 5, 1)->nullable();
            $table->json('recommended_instead')->nullable();
            $table->json('domain_types')->nullable();
            $table->json('insights')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->foreignId('converted_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn('is_audit');
        });
    }
};
