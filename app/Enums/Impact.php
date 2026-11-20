<?php

namespace App\Enums;

enum Impact: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Higher is more important (for sorting and priority). */
    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::High => 'danger',
            self::Medium => 'warning',
            self::Low => 'neutral',
        };
    }
}
