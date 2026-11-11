<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchPrompt extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TestRun::class, 'search_prompt_id');
    }
}
