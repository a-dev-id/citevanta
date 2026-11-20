<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
