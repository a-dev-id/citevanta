<?php

namespace App\Models;

use App\Enums\AiPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One insight AI call (accuracy check, competitor reasons, content): tokens,
 * cost and parsed result. (scan_run_id, purpose, subject) is unique, so a
 * retried job finds its own call instead of paying for a second one.
 */
#[Fillable(['team_id', 'hotel_id', 'scan_run_id', 'purpose', 'subject', 'model', 'tokens_in', 'tokens_out', 'cost_usd', 'result', 'error'])]
class AiCall extends Model
{
    protected function casts(): array
    {
        return [
            'purpose' => AiPurpose::class,
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost_usd' => 'decimal:6',
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }
}
