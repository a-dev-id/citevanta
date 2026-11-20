<?php

namespace App\Models;

use App\Enums\Effort;
use App\Enums\Impact;
use App\Enums\RecommendationCategory;
use App\Enums\RecommendationStatus;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An action item for the hotel team (see RecommendationService).
 *
 * steps: list<string>; evidence: list<array{type: string, label: string, url: ?string}>;
 * metric: array{type: 'persona'|'prompt', value: string|int}|null
 */
#[Fillable([
    'hotel_id', 'key', 'category', 'title', 'why', 'steps', 'where', 'effort', 'impact', 'priority', 'status',
    'evidence', 'metric', 'ai_written', 'draft_hash', 'first_seen_scan_id', 'last_seen_scan_id', 'looks_done_at',
    'status_changed_by', 'status_changed_at', 'dismissed_reason', 'done_at', 'done_baseline_scan_id',
])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => RecommendationCategory::class,
            'status' => RecommendationStatus::class,
            'effort' => Effort::class,
            'impact' => Impact::class,
            'priority' => 'integer',
            'steps' => 'array',
            'evidence' => 'array',
            'metric' => 'array',
            'ai_written' => 'boolean',
            'looks_done_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function doneBaselineScan(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class, 'done_baseline_scan_id');
    }

    /** @return HasMany<ContentPiece, $this> */
    public function contentPieces(): HasMany
    {
        return $this->hasMany(ContentPiece::class);
    }

    /**
     * @param  Builder<Recommendation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', RecommendationStatus::openValues());
    }

    /**
     * High impact, 15 minutes or 1 hour, not done or dismissed.
     */
    public function isQuickWin(): bool
    {
        return $this->status->isOpen() && $this->impact === Impact::High && $this->effort->isLow();
    }
}
