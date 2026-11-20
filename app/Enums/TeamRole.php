<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
