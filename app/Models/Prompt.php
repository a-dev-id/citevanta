<?php

namespace App\Models;

use App\Enums\Persona;
use App\Enums\PromptSource;
use Database\Factories\PromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['hotel_id', 'text', 'persona', 'language', 'source', 'is_edited', 'is_active'])]
class Prompt extends Model
{
    /** @use HasFactory<PromptFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'persona' => Persona::class,
            'source' => PromptSource::class,
            'is_edited' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return HasMany<ScanResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(ScanResponse::class);
    }
}
