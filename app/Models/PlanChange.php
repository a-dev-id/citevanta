<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array'];
    }
}
