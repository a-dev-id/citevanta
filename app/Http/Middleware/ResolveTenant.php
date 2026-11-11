<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $previous = [$context->organizationId, $context->brandId, $context->actorId];
        try {
            $context->clear();
            $organization = $request->session()->get('organization_id');
            $membership = ($request->user()->is_platform_admin ? Organization::query() : $request->user()->organizations()->where('status', 'active'))->when($organization, fn ($q) => $q->where('organizations.id', $organization))->when($request->user()->is_platform_admin, fn ($q) => $q->orderByDesc('is_test_fixture'))->orderBy('organizations.id')->first();
            abort_unless($membership, 403, 'No active organization membership.');
            $brand = $request->session()->get('monitored_brand_id');
            if ($brand) {
                $selected = DB::table('monitored_brands')->where('organization_id', $membership->id)->where('id', $brand)->first();
                abort_unless($selected, 403);
                if (! $selected->active) {
                    $brand = null;
                }
            }
            $brandRow = DB::table('monitored_brands')->where('organization_id', $membership->id)->where('active', true)->when($brand, fn ($q) => $q->where('id', $brand))->orderBy('id')->first();
            abort_unless($brandRow, 403, 'Select an active brand in this organization.');
            $context->set($membership->id, $brandRow->id, $request->user());
            $request->session()->put(['organization_id' => $membership->id, 'monitored_brand_id' => $brandRow->id]);

            return $next($request);
        } finally {
            [$context->organizationId,$context->brandId,$context->actorId] = $previous;
        }
    }
}
