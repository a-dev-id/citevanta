<?php

namespace App\Enums;

enum RecommendationStatus: string
{
    case New = 'new';
    case ToDo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::ToDo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Dismissed => 'Dismissed',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::New => 'info',
            self::ToDo => 'primary',
            self::InProgress => 'warning',
            self::Done => 'success',
            self::Dismissed => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Dismissed], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::New->value, self::ToDo->value, self::InProgress->value];
    }
}
