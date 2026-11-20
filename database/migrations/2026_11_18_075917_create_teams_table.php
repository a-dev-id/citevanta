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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan')->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Makassar');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->after('password')->constrained('teams')->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('current_team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_team_id');
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
