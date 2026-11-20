<?php

namespace App\Models;

use Database\Factories\AuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A free AI visibility audit request (a website lead). See AuditService.
 *
 * recommended_instead: list<array{name: string, answers: int}>
 * domain_types: array<string, int> DomainType value => citations
 * insights: list<string>
 */
#[Fillable([
    'token', 'hotel_name', 'city', 'country', 'website', 'email', 'consent', 'locale', 'ip_hash', 'dedupe_key',
    'source_audit_id', 'hotel_id', 'scan_run_id', 'status', 'questions', 'questions_mentioned', 'visibility',
    'recommended_instead', 'domain_types', 'insights', 'cost_usd', 'error', 'finished_at', 'email_sent_at',
    'converted_team_id', 'converted_at',
])]
class Audit extends Model
{
    /** @use HasFactory<AuditFactory> */
    use HasFactory;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** The AI budget was used up mid-way; resumes from Admin > System. */
    public const PAUSED = 'paused_budget';

    protected function casts(): array
    {
        return [
            'consent' => 'boolean',
            'questions' => 'integer',
            'questions_mentioned' => 'integer',
            'visibility' => 'float',
            'recommended_instead' => 'array',
            'domain_types' => 'array',
            'insights' => 'array',
            'cost_usd' => 'decimal:6',
            'finished_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Audit, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Audit::class, 'source_audit_id');
    }

    /** @return HasMany<Audit, $this> */
    public function reuses(): HasMany
    {
        return $this->hasMany(Audit::class, 'source_audit_id');
    }

    /** @return BelongsTo<ScanRun, $this> */
    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function convertedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'converted_team_id');
    }

    /**
     * The audit whose results this one shows (itself, or the earlier audit it reuses).
     */
    public function results(): Audit
    {
        return $this->source ?? $this;
    }

    public function isFinished(): bool
    {
        return in_array($this->results()->status, [self::COMPLETED, self::FAILED], true);
    }
}
