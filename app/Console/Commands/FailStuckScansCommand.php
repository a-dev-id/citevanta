<?php

namespace App\Console\Commands;

use App\Services\Scanning\ScanMaintenance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:fail-stuck-scans')]
#[Description('Close running scans with no new answer for 90 minutes, keeping what they collected')]
class FailStuckScansCommand extends Command
{
    public function handle(ScanMaintenance $scans): int
    {
        $this->info($scans->failStuck().' stuck scan(s) closed.');

        return self::SUCCESS;
    }
}
