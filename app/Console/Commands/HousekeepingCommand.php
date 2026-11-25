<?php

namespace App\Console\Commands;

use App\Models\TrackingEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:housekeeping')]
#[Description('Prune job batches older than 48 hours, failed jobs older than 30 days and website script events past the retention')]
class HousekeepingCommand extends Command
{
    public function handle(): int
    {
        $this->call('queue:prune-batches', ['--hours' => 48, '--unfinished' => 48]);
        $this->call('queue:prune-failed', ['--hours' => 24 * 30]);

        // Website script events are kept 13 months (TRACKING_RETENTION_MONTHS), then pruned.
        $deleted = TrackingEvent::query()->where('occurred_at', '<', now()->subMonths((int) config('citevanta.tracking.retention_months', 13)))->delete();
        $this->line("Pruned {$deleted} website script event(s).");

        return self::SUCCESS;
    }
}
