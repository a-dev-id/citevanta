<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpendingSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['monthly_limit_micros' => 'integer', 'batch_request_limit' => 'integer', 'safety_percent' => 'integer'];
    }
}
