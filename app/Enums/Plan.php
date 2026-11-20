<?php

namespace App\Enums;

enum Plan: string
{
    case Trial = 'trial';
    case Starter = 'starter';
    case Pro = 'pro';
    /** One hotel on its own OpenAI key; price and limits set per team by an admin. */
    case Private = 'private';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Order for upgrades and downgrades: trial < starter < pro. */
    public function rank(): int
    {
        return match ($this) {
            self::Trial => 0,
            self::Starter => 1,
            self::Pro => 2,
            self::Private => 3,
        };
    }

    public function isPaid(): bool
    {
        return $this !== self::Trial;
    }
}
