<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetMonth extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['opening_micros' => 'integer', 'opening_through_id' => 'integer'];
    }
}
