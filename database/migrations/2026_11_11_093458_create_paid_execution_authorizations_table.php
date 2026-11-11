<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_execution_authorizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('status')->default('disabled');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('pilot_fingerprint', 64)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_test_run_id')->nullable()->unique()->constrained('test_runs')->restrictOnDelete();
            $table->string('request_fingerprint', 64)->nullable();
            $table->timestamps();
        });
        DB::table('paid_execution_authorizations')->insert(['id' => 1, 'status' => 'disabled', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_execution_authorizations');
    }
};
