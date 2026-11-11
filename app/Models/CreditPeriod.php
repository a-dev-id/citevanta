<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['plan_snapshot' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'allowance' => 'integer'];
    }
}
