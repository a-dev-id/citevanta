<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:google-resync {team : Team id or part of its name}')]
#[Description('Copy the 13 months of GA4 data again for a team, window by window; stored rows are replaced by date, so reports never go empty')]
class GoogleResyncCommand extends Command
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

        $result = $sync->resyncTeam($team->id);

        $this->line("{$team->name}: {$result['hotels']} hotel(s), {$result['jobs']} job chain(s) started. The data fills in from the oldest date to the newest; the old rows stay until replaced.");

        return self::SUCCESS;
    }
}
