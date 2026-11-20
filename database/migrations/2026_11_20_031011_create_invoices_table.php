<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices. Amounts are integers in minor units: rupiah for IDR, cents for USD.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('kind', 20);
            $table->string('status', 20)->index();
            $table->string('provider', 20);
            $table->boolean('automatic')->default(false);
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->string('description');
            $table->string('plan', 20);
            $table->string('interval', 10);
            $table->unsignedSmallInteger('hotels');
            $table->unsignedSmallInteger('months');
            $table->string('currency', 3);
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('tax_included')->default(true);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedInteger('unique_code')->default(0);
            $table->unsignedBigInteger('amount_due');
            $table->timestamp('due_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_name')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payment_note')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('due_reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
