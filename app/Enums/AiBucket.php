<?php

namespace App\Enums;

/**
 * Monthly AI budget buckets (config citevanta.budget). Paid customers use
 * what the total leaves after the other buckets.
 */
enum AiBucket: string
{
    case Paid = 'paid';
    case Audits = 'audits';
    case Trials = 'trials';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paying customers',
            self::Audits => 'Free audits',
            self::Trials => 'Trials',
            self::Admin => 'Admin and tests',
        };
    }
}
