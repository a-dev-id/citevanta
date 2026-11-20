<?php

namespace App\Models;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use Database\Factories\ScanRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'hotel_id', 'engine', 'model', 'trigger', 'triggered_by', 'batch_id', 'prompt_ids', 'runs_per_prompt',
    'status', 'hold_reason', 'started_at', 'finished_at', 'answers_count', 'failed_count', 'errors', 'citation_summary', 'cost_usd', 'cost_breakdown',
])]
class ScanRun extends Model
{
    /** @use HasFactory<ScanRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'trigger' => ScanTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_usd' => 'decimal:6',
            'prompt_ids' => 'array',
            'runs_per_prompt' => 'integer',
            'answers_count' => 'integer',
            'failed_count' => 'integer',
            'errors' => 'array',
            'citation_summary' => 'array',
            'cost_breakdown' => 'array',
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

    /** @return HasMany<VisibilityScore, $this> */
    public function visibilityScores(): HasMany
    {
        return $this->hasMany(VisibilityScore::class);
    }

    /** @return HasMany<CompetitorScore, $this> */
    public function competitorScores(): HasMany
    {
        return $this->hasMany(CompetitorScore::class);
    }

    /** @return HasMany<AiCall, $this> */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Engine keys this scan uses (stored comma-separated in "engine").
     *
     * @return list<string>
     */
    public function engineList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->engine))));
    }

    /**
     * Number of API calls the scan plans: prompts x runs x engines (0 until planned).
     */
    public function plannedCalls(): int
    {
        return count($this->prompt_ids ?? []) * (int) $this->runs_per_prompt * count($this->engineList());
    }

    /**
     * Overall visibility across engines, from the stored scores.
     */
    public function overallVisibility(): ?float
    {
        $rows = $this->visibilityScores->whereNull('persona');
        $answers = $rows->sum('answers');

        return $answers > 0 ? round($rows->sum('mentions') / $answers * 100, 1) : null;
    }
}
