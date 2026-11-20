<?php

namespace App\Models;

use Database\Factories\ScanResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One answer from an AI engine to one prompt (table: responses).
 * Named ScanResponse to avoid confusion with Illuminate\Http\Response.
 */
#[Table('responses')]
#[Fillable(['scan_run_id', 'prompt_id', 'engine', 'model', 'run_number', 'answer_text', 'tokens_in', 'tokens_out', 'web_search_calls'])]
class ScanResponse extends Model
{
    /** @use HasFactory<ScanResponseFactory> */
    use HasFactory;

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

    /** @return HasMany<Citation, $this> */
    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class, 'response_id');
    }

    /** @return HasOne<ResponseAnalysis, $this> */
    public function analysis(): HasOne
    {
        return $this->hasOne(ResponseAnalysis::class, 'response_id');
    }
}
