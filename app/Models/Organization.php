<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    protected function casts(): array
    {
        return ['is_test_fixture' => 'boolean', 'monthly_limit_micros' => 'integer', 'owner_id' => 'integer'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function brands(): HasMany
    {
        return $this->hasMany(MonitoredBrand::class);
    }
}
