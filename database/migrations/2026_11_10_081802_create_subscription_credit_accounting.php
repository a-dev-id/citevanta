<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('plan_key');
            $t->json('plan_snapshot');
            $t->unsignedInteger('allowance');
            $t->timestamps();
            $t->unique(['organization_id', 'starts_at']);
            $t->unique(['organization_id', 'id']);
        });
        Schema::create('plan_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('credit_period_id');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->json('before_snapshot');
            $t->json('after_snapshot');
            $t->timestamps();
            $t->foreign(['organization_id', 'credit_period_id'])->references(['organization_id', 'id'])->on('credit_periods')->restrictOnDelete();
        });
        Schema::create('credit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('credit_period_id');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('operation_key', 160);
            $t->unsignedInteger('credits');
            $t->string('status');
            $t->string('plan_key');
            $t->string('provider');
            $t->string('model');
            $t->foreignId('batch_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('test_run_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('improvement_explanation_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'credit_period_id', 'operation_key'], 'credit_operation_unique');
            $t->index(['organization_id', 'status']);
            $t->foreign(['organization_id', 'credit_period_id'])->references(['organization_id', 'id'])->on('credit_periods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('credit_entries')->exists() || DB::table('plan_changes')->exists()) {
            throw new RuntimeException('Saved credit history must be preserved; rollback refused.');
        }
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('plan_changes');
        Schema::dropIfExists('credit_periods');
    }
};
