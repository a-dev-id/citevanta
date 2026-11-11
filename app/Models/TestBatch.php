<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TestBatch extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pilot_snapshot' => 'array', 'pilot_enabled_at' => 'datetime', 'pilot_budget_micros' => 'integer', 'configuration' => 'array', 'pricing_snapshot' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'estimated_micros' => 'integer', 'reserved_estimate_micros' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BatchItem::class, 'test_batch_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class, 'test_batch_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function usages(): HasManyThrough
    {
        return $this->hasManyThrough(ApiUsage::class, TestRun::class, 'test_batch_id', 'test_run_id');
    }

    public function costs(): HasManyThrough
    {
        return $this->hasManyThrough(CostEntry::class, TestRun::class, 'test_batch_id', 'test_run_id');
    }
}
