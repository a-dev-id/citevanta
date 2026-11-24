@php
    $weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $currency = $settings['currency'] ?: '';
    $canPublish = $rooms->isNotEmpty() && filled($hotel->currency);
@endphp

<div>
    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
    @endif

    @if (! $freshness['has_rates'])
        <div class="alert alert-info d-flex gap-2" role="status" id="rates-empty">
            <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
            <div>No rates entered yet. Add a room type, then fill the calendar. Dates without a rate show "rates on request" on your public page.</div>
        </div>
    @elseif ($freshness['stale'])
        <div class="alert alert-warning d-flex gap-2" role="alert" id="rates-stale">
            <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>Your rates are {{ $freshness['days_since'] }} days old.</strong> You last saved a rate on {{ $freshness['last_updated']->setTimezone($hotel->team->timezoneName())->format('j M Y') }}.
                Your public page shows this date as "last updated". Open the fill form below and save your current rates again (even if they did not change) so AI assistants see they are current. The warning appears after {{ $stale }} days.
            </div>
        </div>
    @else
        <p class="small text-muted" id="rates-fresh"><i class="fa-regular fa-clock me-1" aria-hidden="true"></i>Rates last saved {{ $freshness['last_updated']->setTimezone($hotel->team->timezoneName())->format('j M Y, H:i') }}.</p>
    @endif

    <div class="row gx-3">
        <div class="col-xl-5">
            <x-card title="Page settings" subtitle="What the public rates page shows around your rates" :collapsible="false">
                <form wire:submit="saveSettings" novalidate>
                    <div class="row g-3">
                        <div class="col-4">
                            <label for="rates-currency" class="form-label">Currency</label>
                            <input id="rates-currency" type="text" wire:model="settings.currency" maxlength="3" placeholder="IDR" @class(['form-control text-uppercase', 'is-invalid' => $errors->has('settings.currency')])>
                            @error('settings.currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="rates-booking-url" class="form-label">Booking engine address</label>
                            <input id="rates-booking-url" type="url" wire:model="settings.booking_url" placeholder="https://book.yourhotel.com/?arrival={checkin}&departure={checkout}&adults={adults}" @class(['form-control', 'is-invalid' => $errors->has('settings.booking_url')])>
                            <div class="form-text">Where "Book direct" goes. Optional placeholders: <code>{checkin}</code>, <code>{checkout}</code>, <code>{adults}</code> (dates are Y-m-d). We add <code>utm_source={{ config('citevanta.rates.utm_source') }}</code> and <code>utm_medium={{ config('citevanta.rates.utm_medium') }}</code> so GA4 shows these visits.</div>
                            @error('settings.booking_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="rates-description" class="form-label">Short description</label>
                            <textarea id="rates-description" rows="3" wire:model="settings.rates_description" maxlength="1000" @class(['form-control', 'is-invalid' => $errors->has('settings.rates_description')])></textarea>
                            @error('settings.rates_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="rates-inclusions" class="form-label">What is included <span class="text-muted fw-normal">(one per line)</span></label>
                            <textarea id="rates-inclusions" rows="3" wire:model="settings.rates_inclusions" maxlength="2000" placeholder="Breakfast for two&#10;Airport transfer" @class(['form-control', 'is-invalid' => $errors->has('settings.rates_inclusions')])></textarea>
                            @error('settings.rates_inclusions')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="rates-subdomain" class="form-label">Own address later <span class="text-muted fw-normal">(optional)</span></label>
                            <input id="rates-subdomain" type="text" wire:model="settings.rates_subdomain" placeholder="rates.yourhotel.com" @class(['form-control', 'is-invalid' => $errors->has('settings.rates_subdomain')])>
                            <div class="form-text">Saved for later: your page is not served on this address yet. When it is, you add one <strong>CNAME</strong> record in your domain's DNS: <code>{{ $settings['rates_subdomain'] ?: 'rates.yourhotel.com' }}</code> pointing to <code>{{ $host }}</code>.</div>
                            @error('settings.rates_subdomain')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input id="rates-published" type="checkbox" role="switch" class="form-check-input" wire:model="settings.rates_published">
                                <label for="rates-published" class="form-check-label">Page is public</label>
                            </div>
                            @error('settings.rates_published')<div class="text-danger small">{{ $message }}</div>@enderror
                            <div class="form-text">
                                @if ($hotel->rates_published)
                                    Public at <a href="{{ $publicUrl }}" target="_blank" rel="noopener">{{ $publicUrl }}</a>. Date pages: <code>{{ $publicUrl }}/2026-12-24</code> and <code>{{ $publicUrl }}/2026-12-24/3-nights</code>.
                                @else
                                    Not public yet. When it is, it is listed in the sitemap and llms.txt and open to search and AI crawlers. The address will be <code>{{ $publicUrl }}</code>.
                                @endif
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3" wire:loading.attr="disabled" wire:target="saveSettings">Save settings</button>
                </form>
            </x-card>

            <x-card title="Room types" subtitle="Each room type gets its own rate" :collapsible="false">
                <ul class="list-group list-group-flush mb-3">
                    @forelse ($rooms as $room)
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2 px-0" wire:key="room-{{ $room->id }}">
                            <div><strong>{{ $room->name }}</strong>@if ($room->description)<div class="small text-muted">{{ $room->description }}</div>@endif</div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" wire:click="editRoom({{ $room->id }})" aria-label="Edit {{ $room->name }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                                <button type="button" class="btn btn-outline-danger" wire:click="deleteRoom({{ $room->id }})" wire:confirm="Remove {{ $room->name }} and all its rates?" aria-label="Remove {{ $room->name }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                            </div>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-muted">No room types yet.</li>
                    @endforelse
                </ul>
                <form wire:submit="saveRoom" novalidate>
                    <div class="mb-2">
                        <label for="room-name" class="form-label">{{ $editingRoom ? 'Rename room type' : 'New room type' }}</label>
                        <input id="room-name" type="text" wire:model="roomName" maxlength="120" placeholder="Deluxe Garden Villa" @class(['form-control', 'is-invalid' => $errors->has('roomName')])>
                        @error('roomName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-2">
                        <label for="room-description" class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label>
                        <input id="room-description" type="text" wire:model="roomDescription" maxlength="500" @class(['form-control', 'is-invalid' => $errors->has('roomDescription')])>
                        @error('roomDescription')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">{{ $editingRoom ? 'Save room type' : 'Add room type' }}</button>
                    @if ($editingRoom)<button type="button" class="btn btn-link btn-sm" wire:click="cancelRoomEdit">Cancel</button>@endif
                </form>
            </x-card>
        </div>

        <div class="col-xl-7">
            <x-card title="Fill rates" subtitle="Set a rate for a date range and the weekdays you choose. A single night is a range of one day." :collapsible="false">
                @if ($rooms->isEmpty())
                    <x-empty-state icon="fa-bed" text="Add a room type first." />
                @else
                    <form wire:submit="saveFill" novalidate id="fill-form">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fill-room" class="form-label">Room type</label>
                                <select id="fill-room" wire:model="fill.room_type_id" @class(['form-select', 'is-invalid' => $errors->has('fill.room_type_id')])>
                                    @foreach ($rooms as $room)<option value="{{ $room->id }}">{{ $room->name }}</option>@endforeach
                                </select>
                                @error('fill.room_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3">
                                <label for="fill-from" class="form-label">From (night)</label>
                                <input id="fill-from" type="date" wire:model="fill.from" @class(['form-control', 'is-invalid' => $errors->has('fill.from')])>
                                @error('fill.from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3">
                                <label for="fill-to" class="form-label">To (night)</label>
                                <input id="fill-to" type="date" wire:model="fill.to" @class(['form-control', 'is-invalid' => $errors->has('fill.to')])>
                                @error('fill.to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <span class="form-label d-block">Weekdays</span>
                                @foreach ($weekdayNames as $number => $name)
                                    <div class="form-check form-check-inline">
                                        <input id="fill-day-{{ $number }}" type="checkbox" class="form-check-input" value="{{ $number }}" wire:model="fill.weekdays">
                                        <label for="fill-day-{{ $number }}" class="form-check-label">{{ $name }}</label>
                                    </div>
                                @endforeach
                                @error('fill.weekdays')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="fill-price" class="form-label">Price per night @if ($currency)<span class="text-muted fw-normal">({{ $currency }})</span>@endif</label>
                                <input id="fill-price" type="number" step="0.01" min="0" wire:model="fill.price" @class(['form-control', 'is-invalid' => $errors->has('fill.price')])>
                                @error('fill.price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="fill-min" class="form-label">Minimum stay (nights)</label>
                                <input id="fill-min" type="number" min="1" max="{{ config('citevanta.rates.max_nights') }}" wire:model="fill.min_stay" @class(['form-control', 'is-invalid' => $errors->has('fill.min_stay')])>
                                @error('fill.min_stay')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input id="fill-available" type="checkbox" role="switch" class="form-check-input" wire:model="fill.is_available">
                                    <label for="fill-available" class="form-check-label">Available to book</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="fill-note" class="form-label">Note <span class="text-muted fw-normal">(optional, shown on the page)</span></label>
                                <input id="fill-note" type="text" wire:model="fill.note" maxlength="255" placeholder="Christmas package: 3 nights minimum" @class(['form-control', 'is-invalid' => $errors->has('fill.note')])>
                                @error('fill.note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveFill">Save rates</button>
                            <button type="button" class="btn btn-outline-danger" wire:click="clearFill" wire:confirm="Remove the rates for these dates? The page will say rates on request.">Remove rates for these dates</button>
                        </div>
                    </form>
                @endif
            </x-card>

            <x-card :collapsible="false" :flush="true" id="rate-calendar">
                <x-slot:actions>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Month">
                        <button type="button" class="btn btn-outline-secondary" wire:click="shiftMonth(-1)" aria-label="Previous month"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                        <span class="btn btn-outline-secondary disabled">{{ $monthDate->format('F Y') }}</span>
                        <button type="button" class="btn btn-outline-secondary" wire:click="shiftMonth(1)" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                    </div>
                </x-slot:actions>
                @if ($rooms->isEmpty())
                    <x-empty-state icon="fa-calendar" text="The calendar appears when you add a room type." />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <caption class="visually-hidden">Rates per night, {{ $monthDate->format('F Y') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Night</th>
                                    @foreach ($rooms as $room)<th scope="col" class="cv-num">{{ $room->name }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($days as $day)
                                    @php($date = $monthDate->copy()->day($day))
                                    <tr wire:key="day-{{ $date->toDateString() }}">
                                        <th scope="row" class="fw-normal">{{ $date->format('D j') }}</th>
                                        @foreach ($rooms as $room)
                                            @php($rate = $rates[$room->id][$date->toDateString()] ?? null)
                                            <td class="cv-num">
                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" wire:click="pickDate('{{ $date->toDateString() }}', {{ $room->id }})"
                                                        title="Edit {{ $date->format('j M') }} for {{ $room->name }}">
                                                    @if ($rate === null)
                                                        <span class="text-muted">—</span>
                                                    @elseif (! $rate->is_available)
                                                        <span class="text-danger">Closed</span>
                                                    @else
                                                        {{ number_format((float) $rate->price, 0) }}@if ($rate->min_stay > 1)<span class="small text-muted"> · min {{ $rate->min_stay }}</span>@endif
                                                    @endif
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted px-3 py-2 mb-0">"—" means no rate: the page says "rates on request". Click a night to change it.</p>
                @endif
            </x-card>
        </div>
    </div>
</div>
