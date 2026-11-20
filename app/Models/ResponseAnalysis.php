<?php

namespace App\Models;

use App\Enums\Sentiment;
use Database\Factories\ResponseAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['response_id', 'hotel_mentioned', 'position', 'sentiment', 'competitors_mentioned', 'other_hotels_mentioned', 'summary', 'tokens_in', 'tokens_out'])]
class ResponseAnalysis extends Model
{
    /** @use HasFactory<ResponseAnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hotel_mentioned' => 'boolean',
            'position' => 'integer',
            'sentiment' => Sentiment::class,
            'competitors_mentioned' => 'array',
            'other_hotels_mentioned' => 'array',
        ];
    }

    /** @return BelongsTo<ScanResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(ScanResponse::class, 'response_id');
    }
}
