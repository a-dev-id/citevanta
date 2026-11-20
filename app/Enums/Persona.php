<?php

namespace App\Enums;

enum Persona: string
{
    case Family = 'family';
    case Business = 'business';
    case Leisure = 'leisure';
    case Adventure = 'adventure';
    case Couple = 'couple';

    /** Plural noun for sentences: "families", "couples". */
    public function travellers(): string
    {
        return match ($this) {
            self::Family => 'families',
            self::Business => 'business travellers',
            self::Leisure => 'leisure travellers',
            self::Adventure => 'active travellers',
            self::Couple => 'couples',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
