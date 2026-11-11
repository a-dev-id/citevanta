<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketScenario extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['search_settings' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class, 'market_scenario_id');
    }
}
