<?php

namespace App\Enums;

enum ScanStatus: string
{
    /** Created by the command path before it starts (kept for scan:test). */
    /** Not started yet: waiting for email verification, trial approval or trial budget (scan_runs.hold_reason). */
    case Waiting = 'waiting';
    /** Stopped because the AI budget (or OpenAI credit) ran out; resumes from Admin > System. */
    case PausedBudget = 'paused_budget';
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    /** @return list<string> */
    public static function inProgress(): array
    {
        return [self::Waiting->value, self::PausedBudget->value, self::Pending->value, self::Queued->value, self::Running->value];
    }

    /** @return list<string> */
    public static function finishedWithResults(): array
    {
        return [self::Completed->value, self::CompletedWithErrors->value];
    }

    public function isInProgress(): bool
    {
        return in_array($this->value, self::inProgress(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PausedBudget => 'Paused (budget)',
            default => str($this->value)->replace('_', ' ')->ucfirst()->toString(),
        };
    }
}
