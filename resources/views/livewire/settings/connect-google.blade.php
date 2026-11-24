@php
    $dot = fn (?string $status) => match ($status) { 'green' => 'success', 'amber' => 'warning', 'red' => 'danger', default => 'neutral' };
    $statusLabel = fn (?string $status) => match ($status) { 'green' => 'OK', 'amber' => 'Check', 'red' => 'Fix', default => 'Not checked' };
@endphp
<div>
    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert alert-danger" role="alert"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i> {{ $error }}</div>
    @endif

    @if (! $hotel)
        <x-card title="Connect Google" :collapsible="false">
            <x-empty-state icon="fa-hotel" text="Add your hotel first." action-label="Add your hotel" :action-url="route('hotels.create')" />
        </x-card>
    @else
        <div class="row gx-3">
            <div class="col-xl-7">
                <x-card title="1. Share access with {{ config('app.name') }}" subtitle="Read-only: we can see reports, never change anything. Share with this service account email." :collapsible="false">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" x-data="{ copied: false }">
                        <code class="fs-6" id="agent-email">{{ $agentEmail ?: 'not available yet' }}</code>
                        <button type="button" class="btn btn-sm btn-outline-primary" @disabled(! $agentEmail)
                                x-on:click="navigator.clipboard.writeText(@js($agentEmail)); copied = true; setTimeout(() => copied = false, 2000)">
                            <i class="fa-solid fa-copy me-1" aria-hidden="true"></i><span x-text="copied ? 'Copied' : 'Copy email'">Copy email</span>
                        </button>
                    </div>
                    @if ($agentEmail)
                        <p class="small text-muted">This is an automated reader for {{ config('app.name') }}, not a person: its address ends in <code>.iam.gserviceaccount.com</code>. Add it exactly as written.</p>
                    @else
                        <div class="alert alert-warning small" role="alert">The address to share with is not available right now. Please try again later; our team has been notified.</div>
                    @endif

                    <h3 class="h6">Google Analytics 4</h3>
                    <ol class="small">
                        <li>Open GA4 and go to <strong>Admin &gt; Property access management</strong>.</li>
                        <li>Click <strong>+</strong> &gt; <strong>Add users</strong> and paste the email above.</li>
                        <li>Choose the role <strong>Viewer</strong>.</li>
                        <li>Untick <strong>Notify new users by email</strong>, then click <strong>Add</strong>.</li>
                    </ol>

                    <h3 class="h6">Google Search Console</h3>
                    <ol class="small mb-0">
                        <li>Open Search Console and go to <strong>Settings &gt; Users and permissions</strong>.</li>
                        <li>Click <strong>Add user</strong> and paste the email above.</li>
                        <li>Permission: <strong>Restricted</strong>, then click <strong>Add</strong>.</li>
                    </ol>
                </x-card>

                <x-card title="2. Pick your property and site" :collapsible="false">
                    <p class="small text-muted">We look for GA4 properties and Search Console sites shared with us that belong to <strong>{{ \App\Services\Support\Url::host($hotel->website) ?: 'your website' }}</strong>.</p>
                    <button type="button" class="btn btn-primary mb-3" wire:click="check" wire:loading.attr="disabled" wire:target="check">
                        <span wire:loading wire:target="check" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Check connection
                    </button>

                    @foreach ($problems as $problem)
                        <div class="alert alert-warning small py-2" role="alert">{{ $problem }}</div>
                    @endforeach

                    @if ($checked || $link)
                        <div class="row gx-3">
                            <div class="col-md-6 mb-3">
                                <label for="property" class="form-label">GA4 property</label>
                                <select id="property" class="form-select" wire:model="property">
                                    <option value="">None</option>
                                    @foreach ($properties as $option)
                                        <option value="{{ $option['property'] }}">{{ $option['displayName'] }} ({{ str_replace('properties/', '', $option['property']) }})</option>
                                    @endforeach
                                    @if ($link?->ga4_property && ! collect($properties)->contains('property', $link->ga4_property))
                                        <option value="{{ $link->ga4_property }}">{{ $link->ga4_property_name }}</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="site" class="form-label">Search Console site</label>
                                <select id="site" class="form-select" wire:model="site">
                                    <option value="">None</option>
                                    @foreach ($sites as $option)
                                        <option value="{{ $option['siteUrl'] }}">{{ $option['siteUrl'] }}</option>
                                    @endforeach
                                    @if ($link?->gsc_site && ! collect($sites)->contains('siteUrl', $link->gsc_site))
                                        <option value="{{ $link->gsc_site }}">{{ $link->gsc_site }}</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" wire:click="link">Save connection</button>
                            @if ($link)
                                <button type="button" class="btn btn-outline-danger" wire:click="disconnect"
                                        wire:confirm="Disconnect Google? Synced data is removed from {{ config('app.name') }}.">Disconnect</button>
                            @endif
                        </div>
                    @endif

                    @if ($link)
                        <p class="small text-muted mt-3 mb-0">
                            Linked: {{ $link->ga4_property_name ?? 'no GA4 property' }} · {{ $link->gsc_site ?? 'no Search Console site' }}.
                            Last sync {{ $link->last_synced_at?->diffForHumans() ?? 'pending' }}.
                            @if ($link->last_sync_error)<span class="text-danger d-block">{{ $link->last_sync_error }}</span>@endif
                        </p>
                    @endif
                </x-card>
            </div>

            <div class="col-xl-5">
                <x-card title="Booking engine" subtitle="Where guests book, for example booking.example.com" :collapsible="false">
                    <form wire:submit="saveBookingDomain" class="d-flex flex-wrap gap-2 align-items-start">
                        <div class="flex-grow-1">
                            <label for="booking-domain" class="visually-hidden">Booking engine domain</label>
                            <input id="booking-domain" type="text" wire:model="bookingDomain" placeholder="booking.example.com"
                                   @class(['form-control', 'is-invalid' => $errors->has('bookingDomain')])>
                            @error('bookingDomain')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Save</button>
                    </form>
                </x-card>

                @if ($link)
                    <x-card title="Tracking health" :subtitle="$link->health_checked_at ? 'Checked '.$link->health_checked_at->diffForHumans() : 'Not checked yet'" :collapsible="false" :flush="true">
                        <x-slot:actions>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1" wire:click="runHealth">Check again</button>
                        </x-slot:actions>
                        <ul class="list-group list-group-flush" wire:poll.15s>
                            @foreach ($checks as $key => $label)
                                @php($result = $health[$key] ?? null)
                                <li class="list-group-item">
                                    <div class="d-flex align-items-center gap-2">
                                        <x-badge-status :label="$statusLabel($result['status'] ?? null)" :variant="$dot($result['status'] ?? null)" />
                                        <span class="fw-semibold">{{ $label }}</span>
                                    </div>
                                    <p class="small mb-1 mt-1">{{ $result['message'] ?? 'Waiting for the first check.' }}</p>
                                    @if (! empty($result['developer']))
                                        <details class="small" x-data="{ copied: false }">
                                            <summary>Send to my web developer</summary>
                                            <textarea class="form-control form-control-sm mt-1" rows="5" readonly x-ref="text">{{ $result['developer'] }}</textarea>
                                            <button type="button" class="btn btn-sm btn-link px-0" x-on:click="navigator.clipboard.writeText($refs.text.value); copied = true">
                                                <span x-text="copied ? 'Copied' : 'Copy text'">Copy text</span>
                                            </button>
                                        </details>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </x-card>

                    <x-card title="Booking dates in GA4" subtitle="Which event parameters hold the stay dates and guests" :collapsible="false">
                        <form wire:submit="saveMapping">
                            @foreach (['checkin' => 'Check-in date', 'checkout' => 'Check-out date', 'adults' => 'Adults', 'children' => 'Children', 'nights' => 'Nights'] as $field => $label)
                                <div class="mb-2">
                                    <label for="map-{{ $field }}" class="form-label small mb-0">{{ $label }}</label>
                                    <select id="map-{{ $field }}" class="form-select form-select-sm" wire:model="mapping.{{ $field }}">
                                        <option value="">Not available</option>
                                        @foreach ($parameters as $parameter)
                                            <option value="{{ $parameter }}">{{ $parameter }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                            <button type="submit" class="btn btn-sm btn-outline-primary mt-1" @disabled($parameters === [])>Confirm mapping</button>
                            @if ($parameters === [])
                                <p class="small text-muted mt-2 mb-0">No event-scoped custom dimensions found yet. See "Booking dates and guests" above for what to send to your web developer.</p>
                            @endif
                        </form>
                    </x-card>
                @endif
            </div>
        </div>
    @endif
</div>
