<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin > Integrations > Google: the service account e-mail (to give to hotels),
 * whether the key file is found and valid (never its contents), the last
 * successful call, and a "Test connection" button.
 */
class GoogleIntegrationController extends Controller
{
    public function show(GoogleAgent $agent): View
    {
        return view('admin.integrations-google', [
            'connection' => $agent->connection(),
            'agentEmail' => $agent->email(),
            'key' => $agent->account()->status(),
            'fake' => (bool) config('services.google.fake'),
            'linkedHotels' => HotelGoogleLink::query()->count(),
        ]);
    }

    /**
     * Ask Google for the GA4 accounts the service account can see. A refused key shows the red banner.
     */
    public function test(GoogleAgent $agent, GoogleApi $api): RedirectResponse
    {
        try {
            $result = $agent->testConnection($api);
        } catch (GoogleApiException $e) {
            return to_route('admin.integrations.google')->with('error', 'Google test failed: '.$e->getMessage());
        }

        $email = $agent->email();

        return to_route('admin.integrations.google')->with('status', $result['properties'] === 0
            ? "Google accepted the key. No GA4 property is shared with {$email} yet."
            : "Google accepted the key. {$result['properties']} GA4 ".($result['properties'] === 1 ? 'property is' : 'properties are')." shared with {$email} ({$result['accounts']} ".($result['accounts'] === 1 ? 'account' : 'accounts').').');
    }
}
