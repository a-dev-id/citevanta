<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hotel extends Model
{
    use BelongsToWorkspace;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'official_websites' => 'array', 'verified_at' => 'datetime'];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(HotelAssessment::class);
    }

    public function isMonitoredBrand(): bool
    {
        return $this->identifier === app(TenantContext::class)->brand()->slug;
    }

    /** Compatibility for legacy integrations; identity is relative to the selected brand. */
    public function isNandini(): bool
    {
        return $this->isMonitoredBrand();
    }
}
