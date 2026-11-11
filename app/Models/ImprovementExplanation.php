<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ImprovementExplanation extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence_snapshot' => 'array', 'request_settings' => 'array', 'raw_metadata' => 'array', 'selection' => 'array', 'usage' => 'array'];
    }

    public function cost(): HasOne
    {
        return $this->hasOne(CostEntry::class);
    }
}
