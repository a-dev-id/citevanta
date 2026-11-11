<?php

namespace App\Http\Middleware;

use App\Services\PilotStudy;
use App\Services\PlanCatalog;
use App\Services\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        if ($request->routeIs('pilot.*')) {
            abort_unless(app(PilotStudy::class)->isTarget(), 404);
        }
        $catalog = app(PlanCatalog::class);
        if ($request->routeIs('improvements.*', 'visibility.opportunities')) {
            $catalog->authorize('advanced_improvements');
        }
        if ($request->routeIs('batches.create', 'batches.preview', 'batches.start', 'batches.process', 'pilot.prepare', 'pilot.enable')) {
            $catalog->authorize('campaigns');
        }
        if ($request->routeIs('pilot.enable', 'organizations.plan')) {
            abort_unless($request->user()->is_platform_admin, 403);
        }

        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $editorPage = $request->routeIs('tests.create', 'prompts.create', 'prompts.edit', 'batches.create');
        if (($write && ! $request->routeIs('logout', 'organizations.switch', 'brands.switch')) || $editorPage) {
            abort_unless($context->canManage($request->user()), 403);
        }
        if ($request->routeIs('batches.settings*')) {
            abort_unless($request->user()->is_platform_admin, 403);
        }

        foreach ($request->route()->parameters() as $record) {
            if ($record instanceof Model && $record->getAttribute('monitored_brand_id') !== null) {
                Gate::authorize($write ? 'update' : 'view', $record);
            }
        }

        return $next($request);
    }
}
