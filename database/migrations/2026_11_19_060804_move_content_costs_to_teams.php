<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content generation is a team cost, not a scan cost:
     *  - ai_calls gets team_id (backfilled from the hotel)
     *  - content calls that were attached to a scan are detached, and their
     *    cost is taken out of that scan's cost_breakdown and cost_usd
     *  - the "ota_description" content type is now "ota_listing_update"
     */
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['team_id', 'purpose', 'created_at']);
        });

        DB::table('ai_calls')->orderBy('id')->chunkById(500, function ($calls): void {
            $teams = DB::table('hotels')->whereIn('id', $calls->pluck('hotel_id')->unique())->pluck('team_id', 'id');

            foreach ($calls as $call) {
                DB::table('ai_calls')->where('id', $call->id)->update(['team_id' => $teams[$call->hotel_id] ?? null]);
            }
        });

        $contentByScan = DB::table('ai_calls')
            ->where('purpose', 'content')
            ->whereNotNull('scan_run_id')
            ->selectRaw('scan_run_id, sum(cost_usd) as cost')
            ->groupBy('scan_run_id')
            ->pluck('cost', 'scan_run_id');

        foreach ($contentByScan as $scanRunId => $cost) {
            $scan = DB::table('scan_runs')->where('id', $scanRunId)->first(['cost_usd', 'cost_breakdown']);

            if ($scan === null) {
                continue;
            }

            $breakdown = json_decode((string) $scan->cost_breakdown, true) ?: [];
            unset($breakdown['content']);

            DB::table('scan_runs')->where('id', $scanRunId)->update([
                'cost_breakdown' => json_encode($breakdown),
                'cost_usd' => max(0, round((float) $scan->cost_usd - (float) $cost, 6)),
            ]);
        }

        DB::table('ai_calls')->where('purpose', 'content')->update(['scan_run_id' => null]);
        DB::table('content_pieces')->where('type', 'ota_description')->update(['type' => 'ota_listing_update']);
    }

    public function down(): void
    {
        DB::table('content_pieces')->where('type', 'ota_listing_update')->update(['type' => 'ota_description']);

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'purpose', 'created_at']);
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
