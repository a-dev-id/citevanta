<?php

namespace App\Console\Commands;

use App\Services\Scanning\ScanMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:dispatch-queued-scans')]
#[Description('Start scans that have been queued for more than 5 minutes without starting')]
class DispatchQueuedScansCommand extends Command
{
    public function handle(ScanMaintenance $scans): int
    {
        $this->info($scans->dispatchQueued().' queued scan(s) dispatched.');

        return self::SUCCESS;
    }
}
