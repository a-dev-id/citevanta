<?php

namespace App\Enums;

enum AccuracyVerdict: string
{
    case Correct = 'correct';
    case Wrong = 'wrong';
    case Outdated = 'outdated';
    case NotInFacts = 'not_in_facts';

    public function label(): string
    {
        return match ($this) {
            self::Correct => 'Correct',
            self::Wrong => 'Wrong',
            self::Outdated => 'Outdated',
            self::NotInFacts => 'Not in your facts',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Verdicts stored as issues (wrong or outdated claims). */
    public function isIssue(): bool
    {
        return $this === self::Wrong || $this === self::Outdated;
    }
}
