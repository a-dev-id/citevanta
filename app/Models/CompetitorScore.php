<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Share of voice in one scan. competitor_id null = the hotel itself.
 */
#[Fillable(['scan_run_id', 'competitor_id', 'mentions', 'visibility_pct', 'share_of_voice_pct'])]
class CompetitorScore extends Model
{
    protected function casts(): array
    {
        return [
            'mentions' => 'integer',
            'visibility_pct' => 'float',
            'share_of_voice_pct' => 'float',
        ];
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    /** @return BelongsTo<Competitor, $this> */
    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class)->withTrashed();
    }
}
