<?php

namespace App\Models;

use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['hotel_id', 'name', 'website'])]
class Competitor extends Model
{
    /** @use HasFactory<CompetitorFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
