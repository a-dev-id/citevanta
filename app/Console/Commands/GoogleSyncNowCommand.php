<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-sync-now {team : Team id or part of its name} {--days=7 : How many of the last days to read}')]
#[Description('Read only the last days of GA4 traffic, totals, events and booking dates for a team, replacing by date (the history is not touched)')]
class GoogleSyncNowCommand extends Command
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

        $days = max(1, min(31, (int) $this->option('days')));
        $result = $sync->syncRecentTeam($team->id, $days);

        $this->line("{$team->name}: {$result['hotels']} hotel(s), {$result['jobs']} job(s) queued for the last {$days} days. Replaced by date; the history is not touched.");

        return self::SUCCESS;
    }
}
