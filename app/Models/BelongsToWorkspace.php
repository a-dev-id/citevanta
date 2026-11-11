<?php

namespace App\Models;

use App\Services\TenantBackfill;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait BelongsToWorkspace
{
    public function initializeBelongsToWorkspace(): void
    {
        $this->mergeGuarded(['organization_id', 'monitored_brand_id']);
        $this->mergeCasts(['organization_id' => 'integer', 'monitored_brand_id' => 'integer']);
    }

    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $context = app(TenantContext::class);
            $query->where($query->getModel()->qualifyColumn('organization_id'), $context->organizationId ?? 0)
                ->where($query->getModel()->qualifyColumn('monitored_brand_id'), $context->brandId ?? 0);
        });
        static::saving(function ($model) {
            $context = app(TenantContext::class);
            $context->require();
            if ($model->exists && ((int) $model->organization_id !== $context->organizationId || (int) $model->monitored_brand_id !== $context->brandId || $model->isDirty(['organization_id', 'monitored_brand_id']))) {
                throw ValidationException::withMessages(['tenant' => 'Record ownership cannot be reassigned.']);
            }
            $model->organization_id = $context->organizationId;
            $model->monitored_brand_id = $context->brandId;
            foreach (TenantBackfill::parents($model->getTable()) as $key => $table) {
                if ($model->$key !== null && ! DB::table($table)->where('id', $model->$key)->where('organization_id', $context->organizationId)->where('monitored_brand_id', $context->brandId)->exists()) {
                    throw ValidationException::withMessages([$key => 'Select a record in the current brand.']);
                }
            }
        });
        static::deleting(function ($model) {
            $context = app(TenantContext::class);
            abort_unless($model->organization_id === $context->organizationId && $model->monitored_brand_id === $context->brandId, 403);
        });
    }
}
