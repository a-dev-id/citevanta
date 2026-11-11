<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:255']);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        if (! $request->user()->is_platform_admin && ! $request->user()->organizations()->where('status', 'active')->exists()) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'No active organization access is available.']);
        }
        $request->session()->regenerate();
        $request->session()->forget(['organization_id', 'monitored_brand_id']);

        return redirect()->intended(route($request->user()->is_platform_admin ? 'platform.organizations' : 'dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
