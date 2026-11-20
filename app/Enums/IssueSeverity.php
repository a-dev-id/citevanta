<?php

namespace App\Enums;

enum IssueSeverity: string
{
    case High = 'high';
    case Medium = 'medium';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
