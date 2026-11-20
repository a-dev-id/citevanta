<?php

namespace App\Models;

use Database\Factories\CitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['response_id', 'url', 'domain', 'title'])]
class Citation extends Model
{
    /** @use HasFactory<CitationFactory> */
    use HasFactory;

    /** @return BelongsTo<ScanResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(ScanResponse::class, 'response_id');
    }
}
