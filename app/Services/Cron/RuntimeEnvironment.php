<?php

namespace App\Services\Cron;

/**
 * The PHP runtime facts the cron runner depends on. Wrapped in a class so
 * tests can simulate a shared-hosting web request.
 */
class RuntimeEnvironment
{
    /**
     * Effective max_execution_time in seconds (0 = unlimited).
     */
    public function maxExecutionTime(): int
    {
        return (int) ini_get('max_execution_time');
    }

    public function setTimeLimit(int $seconds): bool
    {
        return function_exists('set_time_limit') && @set_time_limit($seconds);
    }

    public function ignoreUserAbort(): void
    {
        ignore_user_abort(true);
    }

    /**
     * Whether proc_open can be used (schedule:run starts tasks as processes).
     */
    public function procOpenAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }

    public function memoryLimit(): string
    {
        return (string) ini_get('memory_limit');
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }
}
