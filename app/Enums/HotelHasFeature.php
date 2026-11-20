<?php

namespace App\Enums;

/**
 * Whether the hotel has a feature a competitor won with, according to its facts.
 */
enum HotelHasFeature: string
{
    case HasIt = 'has_it';
    case LacksIt = 'lacks_it';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::HasIt => 'You have this',
            self::LacksIt => 'You don\'t have this',
            self::Unknown => 'Not in your facts',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::HasIt => 'success',
            self::LacksIt => 'neutral',
            self::Unknown => 'warning',
        };
    }
}
