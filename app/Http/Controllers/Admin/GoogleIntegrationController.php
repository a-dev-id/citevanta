<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin > Integrations > Google: connect the agent account once (OAuth with
 * offline access and the consent prompt), see its status, reconnect.
 */
class GoogleIntegrationController extends Controller
{
    private const STATE_KEY = 'google_oauth_state';

    public function show(GoogleAgent $agent): View
    {
        return view('admin.integrations-google', [
            'connection' => $agent->connection(),
            'agentEmail' => (string) config('services.google.agent_email'),
            'configured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            'fake' => (bool) config('services.google.fake'),
            'redirectUri' => GoogleAgent::redirectUri(),
            'linkedHotels' => HotelGoogleLink::query()->count(),
        ]);
    }

    public function connect(Request $request, GoogleApi $api): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($api->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleAgent $agent, GoogleApi $api): RedirectResponse
    {
        $expected = (string) $request->session()->pull(self::STATE_KEY, '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            return to_route('admin.integrations.google')->with('error', 'The Google sign-in expired or did not come from this page. Please try again.');
        }

        if ($request->filled('error')) {
            return to_route('admin.integrations.google')->with('error', 'Google sign-in was cancelled ('.Str::limit((string) $request->query('error'), 60).').');
        }

        try {
            $agent->connect($api, (string) $request->query('code'));
        } catch (GoogleApiException $e) {
            return to_route('admin.integrations.google')->with('error', $e->getMessage());
        }

        return to_route('admin.integrations.google')->with('status', 'Google connected. Hotels can now share their GA4 and Search Console with the agent email.');
    }
}
