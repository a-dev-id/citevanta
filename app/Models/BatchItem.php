<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchItem extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prompt_snapshot' => 'array', 'market_snapshot' => 'array', 'available_at' => 'datetime', 'claimed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TestBatch::class, 'test_batch_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'test_run_id');
    }
}
