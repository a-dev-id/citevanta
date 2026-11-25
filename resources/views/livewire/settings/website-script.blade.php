<div>
    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
    @endif

    @if (! $hotel)
        <x-card title="Website script" :collapsible="false">
            <x-empty-state icon="fa-hotel" text="Add your hotel first." action-label="Add your hotel" :action-url="route('hotels.create')" />
        </x-card>
    @else
        <div class="row gx-3">
            <div class="col-xl-7">
                <x-card title="1. Add this line to your website" subtitle="Give it to your web developer. It goes before the closing &lt;/head&gt; tag, on every page." :collapsible="false">
                    <div x-data="{ copied: false }">
                        <pre class="small bg-body-tertiary border rounded p-3 mb-2 text-wrap" id="script-snippet">{{ $snippet }}</pre>
                        <button type="button" class="btn btn-sm btn-outline-primary" x-on:click="navigator.clipboard.writeText(@js($snippet)); copied = true; setTimeout(() => copied = false, 2000)">
                            <i class="fa-solid fa-copy me-1" aria-hidden="true"></i><span x-text="copied ? 'Copied' : 'Copy the line'">Copy the line</span>
                        </button>
                    </div>
                    <p class="small text-muted mt-3 mb-0">The script loads in the background and cannot slow down or break your pages. It sets no cookies, stores nothing in the visitor's browser and sends no IP address or personal data. Your public key <code>{{ $tracking->public_key }}</code> is in the page source of your website; it only lets the script report for your hotel, from your own website.</p>
                </x-card>

                <x-card title="2. What the script reads" subtitle="The defaults work for most booking links. Leave a field empty to use the default." :collapsible="false">
                    <form wire:submit="save">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="enabled" wire:model="enabled">
                            <label class="form-check-label" for="enabled">The script is on (turn it off to stop counting without touching your website)</label>
                        </div>

                        <div class="mb-3">
                            <label for="selectors" class="form-label">Booking links and buttons (CSS selectors, one per line)</label>
                            <textarea id="selectors" rows="4" wire:model="selectors" @class(['form-control font-monospace small', 'is-invalid' => $errors->has('selectors')]) placeholder="{{ implode("\n", $defaults['booking_selectors']) }}"></textarea>
                            @error('selectors')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ $booking !== '' ? 'Links to '.$booking.' are always included.' : 'Add your booking engine domain in Connect Google to include every link to it.' }}</div>
                        </div>

                        <h3 class="h6">Parameter names in the booking link</h3>
                        <div class="row gx-3">
                            @foreach (['checkin' => 'Check-in date', 'checkout' => 'Check-out date', 'adults' => 'Adults', 'children' => 'Children'] as $field => $label)
                                <div class="col-md-6 mb-2">
                                    <label for="param-{{ $field }}" class="form-label small mb-0">{{ $label }}</label>
                                    <input id="param-{{ $field }}" type="text" wire:model="params.{{ $field }}" placeholder="{{ implode(', ', array_slice($defaults['link_params'][$field], 0, 4)) }}"
                                           @class(['form-control form-control-sm', 'is-invalid' => $errors->has('params.'.$field)])>
                                    @error('params.'.$field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>

                        <h3 class="h6 mt-3">Fields on the page (used when the link has no dates)</h3>
                        <div class="row gx-3">
                            @foreach (['checkin' => 'Check-in field', 'checkout' => 'Check-out field', 'adults' => 'Adults field', 'children' => 'Children field'] as $field => $label)
                                <div class="col-md-6 mb-2">
                                    <label for="input-{{ $field }}" class="form-label small mb-0">{{ $label }}</label>
                                    <input id="input-{{ $field }}" type="text" wire:model="inputs.{{ $field }}" placeholder="CSS selector (default: by name)"
                                           @class(['form-control form-control-sm font-monospace', 'is-invalid' => $errors->has('inputs.'.$field)])>
                                    @error('inputs.'.$field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3 mb-3">
                            <label for="date-format" class="form-label small mb-0">A date like 31/12/2026 means</label>
                            <select id="date-format" class="form-select form-select-sm w-auto" wire:model="dateFormat">
                                <option value="dmy">day / month / year</option>
                                <option value="mdy">month / day / year</option>
                            </select>
                            <div class="form-text">Dates written as 2026-12-31 are always understood.</div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </x-card>
            </div>

            <div class="col-xl-5">
                <x-card title="3. Check the installation" :collapsible="false">
                    <p class="small">We open your website's home page and look for the line above.</p>
                    <button type="button" class="btn btn-primary btn-sm mb-3" wire:click="checkInstallation" wire:loading.attr="disabled" wire:target="checkInstallation" id="check-installation">
                        <span wire:loading wire:target="checkInstallation" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Check installation
                    </button>

                    @php($result = $checkResult ?? ($tracking->last_check_result ? ['installed' => (bool) $tracking->installed, 'message' => $tracking->last_check_result] : null))
                    @if ($result)
                        <div @class(['alert small mb-2', 'alert-success' => $result['installed'], 'alert-warning' => ! $result['installed']]) role="status" id="check-result">{{ $result['message'] }}</div>
                        @if ($tracking->last_checked_at)<p class="small text-muted mb-0">Checked {{ $tracking->last_checked_at->diffForHumans() }}.</p>@endif
                    @endif
                </x-card>

                <x-card title="Seen by the script" :collapsible="false">
                    <p class="mb-1"><strong>{{ number_format($seen['ai']) }}</strong> visits from AI assistants and <strong>{{ number_format($seen['clicks']) }}</strong> booking clicks in the last 30 days.</p>
                    <p class="small text-muted mb-0">{{ $tracking->last_seen_at ? 'Last event '.$tracking->last_seen_at->diffForHumans().'.' : 'Nothing received yet.' }} These counts also appear in Reports, in the AI traffic report.</p>
                </x-card>

                <x-card title="Debug mode" :collapsible="false">
                    <p class="small mb-0">Open any page of your website with <code>?citevanta_debug=1</code> at the end of the address, for example <code>{{ $hotel->website ?: 'https://www.your-hotel.com' }}/?citevanta_debug=1</code>. A small box in the corner lists what the script detects: AI visits and booking clicks with the dates it read. Nothing extra is sent.</p>
                </x-card>

                <x-card title="For your privacy policy" :collapsible="false">
                    <div x-data="{ copied: false }">
                        <p class="small" id="privacy-sentence">{{ $privacy }}</p>
                        <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="navigator.clipboard.writeText(@js($privacy)); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-text="copied ? 'Copied' : 'Copy text'">Copy text</span>
                        </button>
                    </div>
                </x-card>
            </div>
        </div>
    @endif
</div>
