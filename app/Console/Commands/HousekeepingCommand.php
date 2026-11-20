<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:housekeeping')]
#[Description('Prune job batches older than 48 hours and failed jobs older than 30 days')]
class HousekeepingCommand extends Command
{
    public function handle(): int
    {
        $this->call('queue:prune-batches', ['--hours' => 48, '--unfinished' => 48]);
        $this->call('queue:prune-failed', ['--hours' => 24 * 30]);

        return self::SUCCESS;
    }
}
