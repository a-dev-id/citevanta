<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why competitors won one prompt in one scan (see CompetitorReasonService).
 *
 * reasons: list<array{competitor: string, reason: string, quote: string, hotel_has: string, fact_key: ?string}>
 * sources: list<array{domain: string, competitors: list<string>, answers: int}>
 */
#[Fillable(['hotel_id', 'scan_run_id', 'prompt_id', 'competitors', 'reasons', 'sources', 'summary', 'wins'])]
class CompetitorInsight extends Model
{
    protected function casts(): array
    {
        return [
            'competitors' => 'array',
            'reasons' => 'array',
            'sources' => 'array',
            'wins' => 'integer',
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

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class)->withTrashed();
    }
}
