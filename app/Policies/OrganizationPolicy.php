<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin || $organization->status === 'active' && $organization->members()->where('users.id', $user->id)->exists();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin || $this->view($user, $organization) && $organization->owner_id === $user->id;
    }

    public function manageBrands(User $user, Organization $organization): bool
    {
        return $user->is_platform_admin || $this->view($user, $organization) && in_array($organization->members()->where('users.id', $user->id)->first()?->pivot->role, ['owner', 'administrator'], true);
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_platform_admin;
    }
}
