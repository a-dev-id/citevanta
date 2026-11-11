<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->boolean('is_test_fixture')->default(false));
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_test_fixture')->default(false));
        Schema::table('test_runs', fn (Blueprint $table) => $table->boolean('is_simulated')->default(false));
    }

    public function down(): void
    {
        if (DB::table('organizations')->where('is_test_fixture', true)->exists()) {
            throw new RuntimeException('Remove dedicated test fixtures before removing their markers.');
        }
        Schema::table('test_runs', fn (Blueprint $table) => $table->dropColumn('is_simulated'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_test_fixture'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('is_test_fixture'));
    }
};
