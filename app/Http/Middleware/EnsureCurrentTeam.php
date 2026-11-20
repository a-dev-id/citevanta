<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes sure the signed-in user has a current team they still belong to.
 */
class EnsureCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->belongsToTeam($user->currentTeam)) {
            $fallback = $user->teams()->orderBy('teams.id')->first();

            abort_if($fallback === null && ! $user->is_admin, 403, 'You are not a member of any team.');

            if ($fallback !== null) {
                $user->switchTeam($fallback);
            }
        }

        return $next($request);
    }
}
