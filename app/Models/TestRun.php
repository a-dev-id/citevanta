<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TestRun extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_simulated' => 'boolean', 'market_snapshot' => 'array', 'request_settings' => 'array', 'executed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('simulation', function (Builder $query) {
            $context = app(TenantContext::class);
            $simulated = $context->organizationId ? (bool) Organization::find($context->organizationId)?->is_test_fixture : false;
            $query->where('is_simulated', $simulated);
        });
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(SearchPrompt::class, 'search_prompt_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(MarketScenario::class, 'market_scenario_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(AiResponse::class, 'test_run_id');
    }

    public function cost(): HasOne
    {
        return $this->hasOne(CostEntry::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TestBatch::class, 'test_batch_id');
    }

    public function usage(): HasOne
    {
        return $this->hasOne(ApiUsage::class, 'test_run_id');
    }
}
