<?php

namespace App\Console\Commands;

use App\Services\Cron\CronRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('citevanta:cron')]
#[Description('Run due scheduled tasks and process queued scan jobs (call every minute from cron)')]
class CitevantaCronCommand extends Command
{
    public function handle(CronRunner $runner): int
    {
        $result = $runner->run();

        if ($result->status === 'busy') {
            $this->line('busy: the previous cron run is still going.');

            return self::SUCCESS;
        }

        $this->line(sprintf('status:    %s', $result->status));
        $this->line(sprintf('mode:      %s (work window %ds)', $result->mode, $result->workWindow));
        $this->line(sprintf('seconds:   %.1f', $result->seconds));
        $this->line(sprintf('scheduler: %s, tasks run: %s', $result->schedulerMode, $result->tasksRun === [] ? 'none' : implode(', ', $result->tasksRun)));
        $this->line(sprintf('jobs:      %d processed, %d pending', $result->jobsProcessed, $result->jobsPending));

        return self::SUCCESS;
    }
}
