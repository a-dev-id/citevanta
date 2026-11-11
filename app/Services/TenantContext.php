<?php

namespace App\Services;

use App\Models\MonitoredBrand;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    public ?int $organizationId = null;

    public ?int $brandId = null;

    public ?int $actorId = null;

    public function set(int $organization, int $brand, ?User $actor = null, bool $allowInactive = false): void
    {
        $valid = DB::table('monitored_brands')->where('id', $brand)->where('organization_id', $organization)->when(! $allowInactive, fn ($q) => $q->where('active', true))->exists();
        abort_unless($valid && DB::table('organizations')->where('id', $organization)->when(! $actor?->is_platform_admin, fn ($q) => $q->where('status', 'active'))->exists(), 403);
        if ($actor && ! $actor->is_platform_admin) {
            abort_unless(DB::table('organization_user')->where('organization_id', $organization)->where('user_id', $actor->id)->exists(), 403);
        }
        $this->organizationId = $organization;
        $this->brandId = $brand;
        $this->actorId = $actor?->id;
    }

    public function clear(): void
    {
        $this->organizationId = $this->brandId = $this->actorId = null;
    }

    public function require(): void
    {
        abort_unless($this->organizationId && $this->brandId, 403, 'Select an authorized organization and brand.');
    }

    public function role(?User $user = null): ?string
    {
        $id = $user?->id ?? $this->actorId;

        if (($user ?? User::find($id))?->is_platform_admin && $this->organizationId) {
            return 'administrator';
        }

        return $id && $this->organizationId ? DB::table('organization_user')->where('organization_id', $this->organizationId)->where('user_id', $id)->value('role') : null;
    }

    public function roleLabel(?User $user = null): string
    {
        $user ??= User::find($this->actorId);

        return $user?->is_platform_admin ? 'Platform Administrator' : ucfirst($this->role($user) ?? '');
    }

    public function canManage(?User $user = null): bool
    {
        return in_array($this->role($user), ['owner', 'administrator'], true);
    }

    public function assertManager(User $user): void
    {
        $this->require();
        abort_unless($this->canManage($user), 403);
    }

    public function organization(): Organization
    {
        $this->require();

        return Organization::findOrFail($this->organizationId);
    }

    public function brand(): MonitoredBrand
    {
        $this->require();

        return MonitoredBrand::findOrFail($this->brandId);
    }

    public function run(int $organization, int $brand, Closure $callback, ?User $actor = null, bool $allowInactive = false): mixed
    {
        $previous = [$this->organizationId, $this->brandId, $this->actorId];
        try {
            $this->set($organization, $brand, $actor, $allowInactive);

            return $callback();
        } finally {
            [$this->organizationId, $this->brandId, $this->actorId] = $previous;
        }
    }
}
