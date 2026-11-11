<?php

namespace App\Policies;

use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

class WorkspaceRecordPolicy
{
    public function view(User $user, Model $record): bool
    {
        $context = app(TenantContext::class);

        return $record->organization_id === $context->organizationId && $record->monitored_brand_id === $context->brandId && $context->role($user) !== null;
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record) && app(TenantContext::class)->canManage($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
