<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleDiagnostics;
use App\Services\Google\GoogleSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'links' => HotelGoogleLink::query()->whereNotNull('ga4_property')->with('hotel.team')->get()
                ->filter(fn (HotelGoogleLink $link) => $link->hotel !== null)
                ->sortBy(fn (HotelGoogleLink $link) => $link->hotel->team?->name.' '.$link->hotel->name)->values(),
        ]);
    }

    /**
     * The same checks as citevanta:google-diagnose, shown on the page. Read-only.
     */
    public function diagnose(Request $request, GoogleDiagnostics $diagnostics): RedirectResponse
    {
        $data = $request->validate(['hotel' => ['required', 'integer', 'exists:hotels,id'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $hotel = Hotel::query()->with('googleLink')->findOrFail($data['hotel']);

        return to_route('admin.integrations.google')->withInput($data)->with('diagnostics', $diagnostics->run($hotel, $data['date'] ?? null));
    }

    /**
     * Copy the 13 months again for the team of the chosen hotel; stored rows are replaced by date.
     */
    public function resync(Request $request, GoogleSync $sync, GoogleAgent $agent): RedirectResponse
    {
        $data = $request->validate(['hotel' => ['required', 'integer', 'exists:hotels,id']]);
        $hotel = Hotel::query()->with('team')->findOrFail($data['hotel']);

        if ($agent->isBroken()) {
            return to_route('admin.integrations.google')->with('error', 'Google refused the service account key. Click Test connection first.');
        }

        $result = $sync->resyncTeam($hotel->team_id);

        return to_route('admin.integrations.google')->with('status', "Re-sync started for {$hotel->team?->name}: {$result['hotels']} hotel(s). The data fills in from the oldest date to the newest and the old rows stay until they are replaced.");
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
