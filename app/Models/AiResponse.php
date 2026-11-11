<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiResponse extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_metadata' => 'array', 'unresolved_hotels' => 'array', 'analyzed_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'test_run_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class, 'ai_response_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(HotelAssessment::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(HotelMention::class, 'ai_response_id');
    }
}
