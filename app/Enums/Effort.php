<?php

namespace App\Enums;

enum Effort: string
{
    case Minutes = '15_minutes';
    case Hour = '1_hour';
    case HalfDay = 'half_day';
    case Ongoing = 'ongoing';

    public function label(): string
    {
        return match ($this) {
            self::Minutes => '15 minutes',
            self::Hour => '1 hour',
            self::HalfDay => 'Half a day',
            self::Ongoing => 'Ongoing',
        };
    }

    /** Lower is quicker (for sorting). */
    public function rank(): int
    {
        return match ($this) {
            self::Minutes => 1,
            self::Hour => 2,
            self::HalfDay => 3,
            self::Ongoing => 4,
        };
    }

    public function isLow(): bool
    {
        return $this === self::Minutes || $this === self::Hour;
    }
}
