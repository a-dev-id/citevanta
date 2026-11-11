<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditEntry extends Model
{
    protected $guarded = [];

    public function period(): BelongsTo
    {
        return $this->belongsTo(CreditPeriod::class, 'credit_period_id');
    }

    protected function casts(): array
    {
        return ['credits' => 'integer'];
    }
}
