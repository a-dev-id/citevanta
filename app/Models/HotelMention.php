<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelMention extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_nandini' => 'boolean', 'is_recommendation' => 'boolean'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(AiResponse::class, 'ai_response_id');
    }
}
