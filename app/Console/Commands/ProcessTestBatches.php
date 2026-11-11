<?php

namespace App\Console\Commands;

use App\Services\BatchProcessor;
use Illuminate\Console\Command;

class ProcessTestBatches extends Command
{
    protected $signature = 'intelligence:batches-process {--limit=1 : Maximum requests in this bounded execution}';

    protected $description = 'Process approved pending batches once, then exit; suitable for cPanel cron';

    public function handle(BatchProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > config('spending.cron_request_limit')) {
            $this->error('Limit must be between 1 and '.config('spending.cron_request_limit').'.');

            return self::FAILURE;
        }
        $count = $processor->process($limit);
        $this->info('Processed '.$count.' API request(s). No persistent worker was started.');

        return self::SUCCESS;
    }
}
