<?php

namespace App\Services\Cron;

final readonly class CronResult
{
    /**
     * @param  list<string>  $tasksRun
     */
    public function __construct(
        public string $status,
        public string $mode = '',
        public float $seconds = 0.0,
        public string $schedulerMode = '',
        public array $tasksRun = [],
        public int $jobsProcessed = 0,
        public int $jobsPending = 0,
        public int $timeLimit = 0,
        public int $workWindow = 0,
        public bool $oneJobPerCall = false,
    ) {}

    public static function busy(): self
    {
        return new self(status: 'busy');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'mode' => $this->mode,
            'seconds' => round($this->seconds, 2),
            'scheduler' => $this->schedulerMode,
            'tasks_run' => $this->tasksRun,
            'jobs_processed' => $this->jobsProcessed,
            'jobs_pending' => $this->jobsPending,
            'time_limit' => $this->timeLimit,
            'work_window' => $this->workWindow,
            'one_job_per_call' => $this->oneJobPerCall,
        ];
    }
}
