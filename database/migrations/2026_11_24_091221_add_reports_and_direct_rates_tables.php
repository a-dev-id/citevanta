<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Milestone 6 steps 4-5: synced booking dates for the demand reports, and
     * the Direct Rates Page (hotel rate settings, room types, rate calendar).
     */
    public function up(): void
    {
        Schema::create('ga4_booking_dates_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('row_key', 40);
            $table->string('event_name', 60);
            $table->date('checkin');
            $table->date('checkout')->nullable();
            $table->unsignedSmallInteger('nights')->nullable();
            $table->unsignedInteger('events')->default(0);

            $table->unique(['hotel_id', 'row_key']);
            $table->index(['hotel_id', 'date']);
            $table->index(['hotel_id', 'checkin']);
        });

        Schema::table('hotel_google_links', function (Blueprint $table) {
            $table->timestamp('booking_dates_synced_at')->nullable()->after('last_synced_at');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->unique()->after('name');
            $table->char('currency', 3)->nullable()->after('country');
            $table->string('booking_url', 500)->nullable()->after('booking_engine_domain');
            $table->text('rates_description')->nullable()->after('booking_url');
            $table->text('rates_inclusions')->nullable()->after('rates_description');
            $table->string('rates_subdomain')->nullable()->after('rates_inclusions');
            $table->boolean('rates_published')->default(false)->after('rates_subdomain');
            // Bumped whenever rates or rate settings change; part of the page cache key.
            $table->unsignedInteger('rates_version')->default(1)->after('rates_published');
        });

        $taken = [];
        foreach (DB::table('hotels')->orderBy('id')->get(['id', 'name']) as $hotel) {
            $base = Str::slug($hotel->name) ?: 'hotel';
            $slug = $base;
            for ($i = 2; in_array($slug, $taken, true); $i++) {
                $slug = $base.'-'.$i;
            }
            $taken[] = $slug;
            DB::table('hotels')->where('id', $hotel->id)->update(['slug' => $slug]);
        }

        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['hotel_id', 'position']);
        });

        Schema::create('hotel_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedTinyInteger('min_stay')->default(1);
            $table->boolean('is_available')->default(true);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['room_type_id', 'date']);
            $table->index(['hotel_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_rates');
        Schema::dropIfExists('room_types');

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'currency', 'booking_url', 'rates_description', 'rates_inclusions', 'rates_subdomain', 'rates_published', 'rates_version']);
        });

        Schema::table('hotel_google_links', function (Blueprint $table) {
            $table->dropColumn('booking_dates_synced_at');
        });

        Schema::dropIfExists('ga4_booking_dates_daily');
    }
};
