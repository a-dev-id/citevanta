<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-backfill {team : Team id or part of its name}')]
#[Description('Read the GA4 days older than the oldest stored day (up to ga4_first_sync_months back), window by window, replacing only those dates')]
class GoogleBackfillCommand extends Command
{
    public function handle(GoogleSync $sync, GoogleAgent $agent): int
    {
        $team = ctype_digit((string) $this->argument('team'))
            ? Team::query()->find((int) $this->argument('team'))
            : Team::query()->where('name', 'like', '%'.$this->argument('team').'%')->first();

        if ($team === null) {
            $this->error('No team found.');

            return self::FAILURE;
        }

        if ($agent->isBroken()) {
            $this->warn('Google refused the service account key: check it in Admin > Integrations > Google (Test connection) first.');

            return self::FAILURE;
        }

        $result = $sync->backfillTeam($team->id);

        $this->line("{$team->name}: {$result['hotels']} hotel(s), {$result['jobs']} older-data job chain(s) started. Only days older than the oldest stored day are read; newer data is not touched.");

        return self::SUCCESS;
    }
}
