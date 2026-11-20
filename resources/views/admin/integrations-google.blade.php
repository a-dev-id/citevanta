<x-layouts::app title="Google" :breadcrumbs="['Admin' => null, 'Integrations' => null, 'Google' => null]">
    <x-flash />

    <div class="row gx-3">
        <div class="col-xl-7">
            <x-card title="Agent account" subtitle="One Google account reads GA4 and Search Console for every hotel that shares access with it" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr><th scope="row">Agent email</th><td>{{ $agentEmail ?: 'Not set (GOOGLE_AGENT_EMAIL)' }}</td></tr>
                        <tr><th scope="row">Connected account</th><td>{{ $connection?->email ?? '—' }}</td></tr>
                        <tr>
                            <th scope="row">Status</th>
                            <td>
                                @if ($fake)
                                    <x-badge-status label="Fake data (no GOOGLE_CLIENT_ID)" variant="info" />
                                @elseif (! $connection)
                                    <x-badge-status label="Not connected" variant="warning" />
                                @elseif ($connection->isWorking())
                                    <x-badge-status label="Connected" variant="success" />
                                @else
                                    <x-badge-status label="Needs reconnecting" variant="danger" />
                                    <span class="d-block small text-danger mt-1">{{ $connection->error }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr><th scope="row">Connected</th><td>{{ $connection?->connected_at?->format('j M Y, H:i') ?? '—' }}</td></tr>
                        <tr><th scope="row">Last successful call</th><td>{{ $connection?->last_success_at?->diffForHumans() ?? '—' }}</td></tr>
                        <tr><th scope="row">Scopes</th><td class="small">analytics.readonly, webmasters.readonly (read only), openid and email (to check the account)</td></tr>
                        <tr><th scope="row">Hotels linked</th><td class="cv-num">{{ $linkedHotels }}</td></tr>
                    </tbody>
                </table>
                <x-slot:footer>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small text-muted flex-grow-1">Sign in with {{ $agentEmail ?: 'the agent account' }} and allow access. While the Google app is in "Testing" mode, Google ends the connection after 7 days.</span>
                        @if ($configured || $fake)
                            <a href="{{ route('admin.integrations.google.connect') }}" class="btn btn-primary btn-sm">{{ $connection ? 'Reconnect' : 'Connect agent account' }}</a>
                        @endif
                    </div>
                </x-slot:footer>
            </x-card>
        </div>
        <div class="col-xl-5">
            <x-card title="Setup" :collapsible="false">
                @unless ($configured)
                    <p class="text-danger small">GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are not set. See docs/GOOGLE-SETUP.md.</p>
                @endunless
                <p class="small mb-1">Authorised redirect URI for the OAuth client:</p>
                <code class="d-block small text-break mb-3">{{ $redirectUri }}</code>
                <p class="small text-muted mb-0">The redirect URI comes from APP_URL. When the app moves to a new domain, add the new URI to the OAuth client and reconnect.</p>
            </x-card>
        </div>
    </div>
</x-layouts::app>
