<?php

namespace App\Console\Commands;

use App\Services\Reporting\WeeklyReportSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:weekly-reports')]
#[Description('Queue the weekly email reports for teams where it is Monday 08:00 or later')]
class WeeklyReportsCommand extends Command
{
    public function handle(WeeklyReportSender $sender): int
    {
        $result = $sender->run();
        $this->line("Teams with a report: {$result['teams']}, emails queued: {$result['emails']}, skipped (no new scans): {$result['skipped']}");

        return self::SUCCESS;
    }
}
