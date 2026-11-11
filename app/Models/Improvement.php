<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Improvement extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $guarded = [];

    public const STATUSES = ['new' => 'New', 'under_review' => 'Under Review', 'approved' => 'Approved', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'dismissed' => 'Dismissed'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'actions' => 'array', 'active' => 'boolean', 'completed_at' => 'datetime', 'refreshed_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImprovementEvent::class);
    }

    public function explanations(): HasMany
    {
        return $this->hasMany(ImprovementExplanation::class);
    }
}
