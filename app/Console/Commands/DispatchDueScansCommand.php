<?php

namespace App\Console\Commands;

use App\Services\Scanning\ScanMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:dispatch-due-scans')]
#[Description('Queue scheduled scans for hotels whose last completed scan is older than 30 / scans_per_month days')]
class DispatchDueScansCommand extends Command
{
    public function handle(ScanMaintenance $scans): int
    {
        $this->info($scans->dispatchDue().' scheduled scan(s) queued.');

        return self::SUCCESS;
    }
}
