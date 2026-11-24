<x-layouts::app title="Google" :breadcrumbs="['Admin' => null, 'Integrations' => null, 'Google' => null]">
    <x-flash />

    @foreach ($dataWarnings as $warning)
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div><strong>{{ $warning['team'] }} - {{ $warning['hotel'] }}:</strong> {{ $warning['text'] }}@if ($warning['checked']) <span class="text-muted small">(checked {{ \Illuminate\Support\Carbon::parse($warning['checked'])->diffForHumans() }})</span>@endif Run diagnostics below, then Re-sync from scratch.</div>
        </div>
    @endforeach

    <x-card title="Diagnostics and re-sync" subtitle="Compare what we stored from GA4 for a hotel with what GA4 returns now" :collapsible="false" class="mb-3">
        @if ($links->isEmpty())
            <p class="small text-muted mb-0">No hotel has a GA4 property linked yet.</p>
        @else
            <form method="POST" action="{{ route('admin.integrations.google.diagnose') }}" class="row g-2 align-items-end mb-3" id="diagnose-form">
                @csrf
                <div class="col-md-5">
                    <label for="diag-hotel" class="form-label small mb-0">Team and hotel</label>
                    <select id="diag-hotel" name="hotel" class="form-select form-select-sm">
                        @foreach ($links as $link)
                            <option value="{{ $link->hotel_id }}" @selected((int) old('hotel') === $link->hotel_id)>{{ $link->hotel->team?->name }} - {{ $link->hotel->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="diag-date" class="form-label small mb-0">Day to compare live</label>
                    <input id="diag-date" type="date" name="date" value="{{ old('date', now()->subDays(10)->toDateString()) }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Run diagnostics</button>
                    <button type="submit" class="btn btn-outline-secondary btn-sm" formaction="{{ route('admin.integrations.google.backfill') }}" id="backfill-button"
                            onclick="return confirm('Read the older GA4 days (back to {{ (int) config('citevanta.google.ga4_first_sync_months', 25) }} months) for this team? Only days older than the oldest stored day are read; the newer data is not touched.')">Backfill older data</button>
                    <button type="submit" class="btn btn-outline-danger btn-sm" formaction="{{ route('admin.integrations.google.resync') }}" id="resync-button"
                            onclick="return confirm('Copy the {{ (int) config('citevanta.google.ga4_first_sync_months', 25) }} months of GA4 data again for this team? Old rows stay until each window is replaced, so reports never go empty. It takes a while.')">Re-sync from scratch</button>
                </div>
            </form>
            <p class="small text-muted">Run diagnostics is read-only and makes a few GA4 requests. Backfill older data reads only the days older than the oldest stored day (for teams synced with fewer months). Re-sync copies the whole GA4 history again for every linked hotel of the chosen team (Search Console is not touched). The same checks run from the command line with <code>php artisan citevanta:google-diagnose</code>; <code>php artisan citevanta:google-resync</code> does the re-sync and <code>php artisan citevanta:google-backfill</code> the backfill.</p>
        @endif

        @if (session('diagnostics'))
            <pre id="diagnostics-output" class="small bg-body-tertiary border rounded p-3 mb-0" style="overflow-x: auto; white-space: pre; max-height: 36rem">{{ implode("\n", session('diagnostics')) }}</pre>
        @endif
    </x-card>

    <div class="row gx-3">
        <div class="col-xl-7">
            <x-card title="Service account" subtitle="One Google service account reads GA4 and Search Console for every hotel that shares access with it" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr>
                            <th scope="row">Service account email</th>
                            <td>
                                @if ($agentEmail)
                                    <div class="d-flex flex-wrap align-items-center gap-2" x-data="{ copied: false }">
                                        <code id="agent-email" class="text-break">{{ $agentEmail }}</code>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                x-on:click="navigator.clipboard.writeText(@js($agentEmail)); copied = true; setTimeout(() => copied = false, 2000)">
                                            <i class="fa-solid fa-copy me-1" aria-hidden="true"></i><span x-text="copied ? 'Copied' : 'Copy email'">Copy email</span>
                                        </button>
                                    </div>
                                    @if ($fake)<span class="d-block small text-muted mt-1">Sample address: no key file is set, so the app uses fake data.</span>@endif
                                @else
                                    <span class="text-muted">Not known until the key file is valid</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Key file</th>
                            <td id="key-status">
                                @if ($fake)
                                    <x-badge-status label="Not set (fake data)" variant="info" />
                                    <span class="d-block small text-muted mt-1">GOOGLE_SERVICE_ACCOUNT_JSON_PATH is empty.</span>
                                @elseif (! $key['found'])
                                    <x-badge-status label="Not found" variant="danger" />
                                    <span class="d-block small text-danger mt-1">{{ $key['problem'] }}</span>
                                @elseif (! $key['valid'])
                                    <x-badge-status label="Found, but not valid" variant="danger" />
                                    <span class="d-block small text-danger mt-1">{{ $key['problem'] }}</span>
                                @else
                                    <x-badge-status label="Found and valid" variant="success" />
                                    <code class="d-block small text-break mt-1">{{ $key['path'] }}</code>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Connection</th>
                            <td>
                                @if ($fake)
                                    <x-badge-status label="Fake data" variant="info" />
                                @elseif (! $connection)
                                    <x-badge-status label="Not tested yet" variant="warning" />
                                @elseif ($connection->isWorking())
                                    <x-badge-status label="Working" variant="success" />
                                @else
                                    <x-badge-status label="Google refused the key" variant="danger" />
                                    <span class="d-block small text-danger mt-1">{{ $connection->error }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr><th scope="row">Last successful call</th><td>{{ $connection?->last_success_at?->format('j M Y, H:i') ?? '—' }}@if ($connection?->last_success_at) <span class="text-muted small">({{ $connection->last_success_at->diffForHumans() }})</span>@endif</td></tr>
                        <tr><th scope="row">Access</th><td class="small">Read only: analytics.readonly and webmasters.readonly</td></tr>
                        <tr><th scope="row">Hotels linked</th><td class="cv-num">{{ $linkedHotels }}</td></tr>
                    </tbody>
                </table>
                <x-slot:footer>
                    <form method="POST" action="{{ route('admin.integrations.google.test') }}" class="d-flex flex-wrap align-items-center gap-2">
                        @csrf
                        <span class="small text-muted flex-grow-1">Asks Google for the GA4 accounts shared with this service account. If Google refuses the key, a red banner shows and the admins get one email.</span>
                        <button type="submit" class="btn btn-primary btn-sm">Test connection</button>
                    </form>
                </x-slot:footer>
            </x-card>
        </div>
        <div class="col-xl-5">
            <x-card title="Setup" :collapsible="false">
                <p class="small">The key file is a JSON file from Google Cloud. It is read from the path in <code>GOOGLE_SERVICE_ACCOUNT_JSON_PATH</code> and is never saved in the database or shown on screen.</p>
                <p class="small mb-1">Hotels share their access with the email above:</p>
                <ul class="small mb-3">
                    <li>GA4: role <strong>Viewer</strong></li>
                    <li>Search Console: permission <strong>Restricted</strong></li>
                </ul>
                <p class="small text-muted mb-0">Steps to create the account, the key and the three APIs are in docs/GOOGLE-SETUP.md.</p>
            </x-card>
        </div>
    </div>
</x-layouts::app>
