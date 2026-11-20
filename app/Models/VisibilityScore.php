<?php

namespace App\Models;

use Database\Factories\VisibilityScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visibility results of one scan, per engine and persona (persona null = overall).
 */
#[Fillable([
    'hotel_id', 'scan_run_id', 'engine', 'persona', 'mentions', 'answers',
    'visibility_pct', 'avg_position', 'positive', 'neutral', 'negative',
])]
class VisibilityScore extends Model
{
    /** @use HasFactory<VisibilityScoreFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mentions' => 'integer',
            'answers' => 'integer',
            'visibility_pct' => 'float',
            'avg_position' => 'float',
            'positive' => 'integer',
            'neutral' => 'integer',
            'negative' => 'integer',
        ];
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
