<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostEntry extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reserved_micros' => 'integer', 'committed_micros' => 'integer', 'usage_cost_micros' => 'integer', 'safety_percent' => 'integer', 'pricing_snapshot' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'test_run_id');
    }
}
