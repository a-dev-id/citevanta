<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Site\PageController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "/" and "/id" — the public home page for guests, the dashboard for signed-in users.
 * A controller (not a closure) so routes can be cached.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, PageController $pages): View|RedirectResponse
    {
        return $request->user() ? to_route('dashboard') : $pages->home();
    }
}
