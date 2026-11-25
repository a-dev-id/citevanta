<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the setup checklist is expanded, minimized or hidden, per user and per team, and how many times
     * its "All set" state was shown. The items themselves are never stored: they tick from real data.
     */
    public function up(): void
    {
        Schema::create('setup_checklist_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('state', 12)->default('expanded');
            $table->unsignedTinyInteger('all_set_views')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_checklist_states');
    }
};
