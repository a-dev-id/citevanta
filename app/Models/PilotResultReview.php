<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PilotResultReview extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
