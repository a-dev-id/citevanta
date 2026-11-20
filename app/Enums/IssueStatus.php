<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
