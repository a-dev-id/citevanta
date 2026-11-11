<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Services\PaidExecutionGate;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->partialMock(PaidExecutionGate::class, function ($mock) {
            $mock->shouldReceive('consume')->andReturnUsing(function (): void {
                if (! Http::preventingStrayRequests()) {
                    throw new \RuntimeException('Legacy provider fixtures require blocked external transport.');
                }
            });
        });
    }

    protected function joinLegacyWorkspace(User $user): void
    {
        $context = app(TenantContext::class);
        $organization = Organization::findOrFail($context->organizationId);
        $organization->members()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
        $organization->forceFill(['owner_id' => $user->id, 'monthly_limit_micros' => 100000000])->save();
        $user->forceFill(['is_platform_admin' => true])->save();
        $context->set($organization->id, $context->brandId, $user);
    }
}
