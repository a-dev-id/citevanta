<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelAssessment extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['mentioned' => 'boolean', 'recommended' => 'boolean', 'rank' => 'integer', 'manual' => 'boolean', 'occurrences' => 'array', 'supporting_citation_ids' => 'array'];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(AiResponse::class, 'ai_response_id');
    }
}
