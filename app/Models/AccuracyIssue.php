<?php

namespace App\Models;

use App\Enums\AccuracyVerdict;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use Database\Factories\AccuracyIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A claim AI assistants make about the hotel that is wrong or outdated, or a
 * topic they mention that the hotel's facts do not cover (verdict
 * not_in_facts, shown as "Missing facts"). See AccuracyService.
 */
#[Fillable([
    'hotel_id', 'claim_key', 'fact_key', 'ai_claim', 'true_value', 'verdict', 'severity', 'quote',
    'first_seen_scan_id', 'last_seen_scan_id', 'occurrences', 'engines', 'example_response_ids',
    'status', 'auto_resolved', 'resolved_at', 'dismissed_reason', 'dismissed_by', 'dismissed_at',
])]
class AccuracyIssue extends Model
{
    /** @use HasFactory<AccuracyIssueFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'verdict' => AccuracyVerdict::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'occurrences' => 'integer',
            'engines' => 'array',
            'example_response_ids' => 'array',
            'auto_resolved' => 'boolean',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function firstSeenScan(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class, 'first_seen_scan_id');
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function lastSeenScan(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class, 'last_seen_scan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * Wrong or outdated claims (not missing facts).
     *
     * @param  Builder<AccuracyIssue>  $query
     */
    public function scopeIssues(Builder $query): void
    {
        $query->whereIn('verdict', [AccuracyVerdict::Wrong->value, AccuracyVerdict::Outdated->value]);
    }

    /**
     * @param  Builder<AccuracyIssue>  $query
     */
    public function scopeMissingFacts(Builder $query): void
    {
        $query->where('verdict', AccuracyVerdict::NotInFacts->value);
    }

    public function isMissingFact(): bool
    {
        return $this->verdict === AccuracyVerdict::NotInFacts;
    }
}
