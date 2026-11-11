<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'model_configuration' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class, 'ai_provider_id');
    }
}
