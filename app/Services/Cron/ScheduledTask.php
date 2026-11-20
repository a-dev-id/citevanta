<?php

namespace App\Services\Cron;

final readonly class ScheduledTask
{
    public function __construct(
        public string $command,
        public string $expression,
        public string $timezone,
        public string $description,
    ) {}

    public function cacheKey(): string
    {
        return 'citevanta:schedule:last-run:'.$this->command;
    }
}
