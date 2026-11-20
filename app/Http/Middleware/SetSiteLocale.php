<?php

namespace App\Http\Middleware;

use App\Services\Site\PublicPages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public website pages: the language comes from the route ("locale" default:
 * en at the root, id under /id), never from the browser, so every URL has
 * exactly one language (needed for hreflang and caching).
 */
class SetSiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) ($request->route()?->defaults['locale'] ?? PublicPages::DEFAULT_LOCALE);

        App::setLocale(in_array($locale, PublicPages::locales(), true) ? $locale : PublicPages::DEFAULT_LOCALE);

        return $next($request);
    }
}
