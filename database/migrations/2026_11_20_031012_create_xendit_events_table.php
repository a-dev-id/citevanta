<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Xendit webhook as received. event_key makes processing idempotent.
     */
    public function up(): void
    {
        Schema::create('xendit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('event', 80)->index();
            $table->string('object_id')->nullable()->index();
            $table->string('status', 20)->default('received');
            $table->json('payload');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xendit_events');
    }
};
