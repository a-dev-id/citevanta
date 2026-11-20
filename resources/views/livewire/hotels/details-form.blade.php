<div>
    @if ($limitMessage)
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                {{ $limitMessage }}
                <a href="{{ route('hotels.index') }}" class="alert-link">See your hotels</a>.
            </div>
        </div>
    @endif

    @if ($saved)
        <div class="alert alert-success" role="status">
            <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> Hotel details saved.
        </div>
    @endif

    <x-card title="Hotel details" subtitle="The basics AI assistants use to describe your hotel." :collapsible="false">
        <form id="details-form-{{ $this->getId() }}" wire:submit="save" novalidate>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="hotel-name" class="form-label">Hotel name</label>
                    <input id="hotel-name" type="text" wire:model="name" autocomplete="organization"
                           @class(['form-control', 'is-invalid' => $errors->has('name')]) required
                           @error('name') aria-describedby="hotel-name-error" aria-invalid="true" @enderror>
                    @error('name')<div id="hotel-name-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label for="hotel-type" class="form-label">Hotel type</label>
                    <select id="hotel-type" wire:model="hotel_type" @class(['form-select', 'is-invalid' => $errors->has('hotel_type')])>
                        @foreach ($hotelTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    @error('hotel_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label for="hotel-city" class="form-label">City</label>
                    <input id="hotel-city" type="text" wire:model="city" autocomplete="address-level2"
                           @class(['form-control', 'is-invalid' => $errors->has('city')]) required
                           @error('city') aria-describedby="hotel-city-error" aria-invalid="true" @enderror>
                    @error('city')<div id="hotel-city-error" class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label for="hotel-country" class="form-label">Country</label>
                    <x-searchable-select id="hotel-country" wire:model="country" :options="$countries"
                                         :invalid="$errors->has('country')" placeholder="Search countries…"
                                         :describedby="$errors->has('country') ? 'hotel-country-error' : null" />
                    @error('country')<div id="hotel-country-error" class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label for="hotel-website" class="form-label">Website <span class="text-muted fw-normal">(optional)</span></label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                        <input id="hotel-website" type="url" wire:model="website" placeholder="https://yourhotel.com" autocomplete="url"
                               @class(['form-control', 'is-invalid' => $errors->has('website')])
                               @error('website') aria-describedby="hotel-website-error" aria-invalid="true" @enderror>
                        @error('website')<div id="hotel-website-error" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="hotel-stars" class="form-label">Star rating <span class="text-muted fw-normal">(optional)</span></label>
                    <select id="hotel-stars" wire:model="star_rating" @class(['form-select', 'is-invalid' => $errors->has('star_rating')])>
                        <option value="">Not rated</option>
                        @foreach (range(1, 5) as $stars)
                            <option value="{{ $stars }}">{{ $stars }} {{ Str::plural('star', $stars) }}</option>
                        @endforeach
                    </select>
                    @error('star_rating')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="hotel-markets" class="form-label">Main guest markets <span class="text-muted fw-normal">(up to {{ $maxMarkets }})</span></label>
                    <x-searchable-select id="hotel-markets" wire:model="guest_markets" :options="$countries" :multiple="true"
                                         :max="$maxMarkets" placeholder="Add a country…"
                                         :invalid="$errors->has('guest_markets') || $errors->has('guest_markets.*')" />
                    @error('guest_markets')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    @error('guest_markets.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label for="hotel-alternative-names" class="form-label">Other names <span class="text-muted fw-normal">(optional, one per line)</span></label>
                    <textarea id="hotel-alternative-names" rows="2" wire:model="alternative_names" aria-describedby="hotel-alternative-names-help"
                              @class(['form-control', 'is-invalid' => $errors->has('alternative_names') || $errors->has('alternative_names.*')])
                              placeholder="e.g. 阿拉亚乌布度假村"></textarea>
                    <div id="hotel-alternative-names-help" class="form-text">How the hotel is written in other languages (Chinese, Korean, Japanese) or a short name guests use. We look for these names in AI answers too.</div>
                    @error('alternative_names')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @error('alternative_names.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </form>

        <x-slot:footer>
            <div class="d-flex justify-content-end gap-2">
                <button type="submit" form="details-form-{{ $this->getId() }}" class="btn btn-primary"
                        @disabled($limitMessage && ! $hotelId) wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ $wizard ? 'Save and continue' : 'Save details' }}
                    @if ($wizard)<i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>@endif
                </button>
            </div>
        </x-slot:footer>
    </x-card>
</div>
