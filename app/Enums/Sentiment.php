<?php

namespace App\Enums;

enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case NotMentioned = 'not_mentioned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
