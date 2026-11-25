<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleDiagnostics;
use App\Services\Google\GoogleSelfCheck;
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
            'dataWarnings' => HotelGoogleLink::query()->whereNotNull('data_check')->with('hotel.team')->get()
                ->map(fn (HotelGoogleLink $link) => ['hotel' => $link->hotel?->name, 'team' => $link->hotel?->team?->name, 'text' => GoogleSelfCheck::warning($link), 'checked' => $link->data_check['checked_at'] ?? null])
                ->filter(fn (array $warning) => $warning['text'] !== null)->values(),
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
     * Copy the whole GA4 history again for the team of the chosen hotel; stored rows are replaced by date.
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
     * Read only the last 7 days for the team of the chosen hotel; replaced by date, the history is not touched.
     */
    public function syncNow(Request $request, GoogleSync $sync, GoogleAgent $agent): RedirectResponse
    {
        $data = $request->validate(['hotel' => ['required', 'integer', 'exists:hotels,id']]);
        $hotel = Hotel::query()->with('team')->findOrFail($data['hotel']);

        if ($agent->isBroken()) {
            return to_route('admin.integrations.google')->with('error', 'Google refused the service account key. Click Test connection first.');
        }

        $result = $sync->syncRecentTeam($hotel->team_id, 7);

        return to_route('admin.integrations.google')->with('status', "Sync started for {$hotel->team?->name}: the last 7 days of {$result['hotels']} hotel(s). They are replaced by date; the history is not touched.");
    }

    /**
     * Read the GA4 days older than the oldest stored day for the team of the chosen hotel.
     */
    public function backfill(Request $request, GoogleSync $sync, GoogleAgent $agent): RedirectResponse
    {
        $data = $request->validate(['hotel' => ['required', 'integer', 'exists:hotels,id']]);
        $hotel = Hotel::query()->with('team')->findOrFail($data['hotel']);

        if ($agent->isBroken()) {
            return to_route('admin.integrations.google')->with('error', 'Google refused the service account key. Click Test connection first.');
        }

        $result = $sync->backfillTeam($hotel->team_id);

        return to_route('admin.integrations.google')->with('status', $result['jobs'] === 0
            ? "Nothing to backfill for {$hotel->team?->name}: the stored data already reaches back ".(int) config('citevanta.google.ga4_first_sync_months', 25).' months, or nothing is stored yet.'
            : "Backfill started for {$hotel->team?->name}: only days older than the oldest stored day are read; newer data is not touched.");
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
