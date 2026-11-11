<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonitoredBrand extends Model
{
    use HasFactory;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'aliases' => 'array', 'verified_pages' => 'array', 'verified_at' => 'datetime', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('organization', fn (Builder $q) => $q->where('organization_id', app(TenantContext::class)->organizationId ?? 0));
        static::saving(function (self $brand) {
            $context = app(TenantContext::class);
            $context->require();
            abort_if($brand->exists && ($brand->organization_id !== $context->organizationId || $brand->isDirty('organization_id')), 403);
            $brand->organization_id = $context->organizationId;
        });
    }
}
