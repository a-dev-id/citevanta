@props(['idPrefix' => 'audit'])
{{-- The free audit form (Home and /free-audit). Posts to AuditController@store. --}}
@php
    $countries = array_values(config('countries'));
    $field = fn (string $name) => $idPrefix.'-'.$name;
@endphp

<form method="POST" action="{{ route('audit.store') }}" novalidate {{ $attributes }}>
    @csrf
    <input type="hidden" name="locale" value="{{ app()->getLocale() }}">

    @if ($errors->audit->any())
        <div class="alert alert-warning py-2 small" role="alert">{{ $errors->audit->first() }}</div>
    @endif

    <div class="row g-2">
        <div class="col-sm-6">
            <label class="form-label" for="{{ $field('hotel') }}">{{ __('site.audit_form.hotel_name') }}</label>
            <input id="{{ $field('hotel') }}" name="hotel_name" type="text" required maxlength="120" autocomplete="organization"
                   value="{{ old('hotel_name') }}" @class(['form-control', 'is-invalid' => $errors->audit->has('hotel_name')])>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="{{ $field('city') }}">{{ __('site.audit_form.city') }}</label>
            <input id="{{ $field('city') }}" name="city" type="text" required maxlength="120" autocomplete="address-level2"
                   value="{{ old('city') }}" @class(['form-control', 'is-invalid' => $errors->audit->has('city')])>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="{{ $field('country') }}">{{ __('site.audit_form.country') }}</label>
            <select id="{{ $field('country') }}" name="country" required @class(['form-select', 'is-invalid' => $errors->audit->has('country')])>
                <option value="">{{ __('site.audit_form.country_placeholder') }}</option>
                @foreach ($countries as $country)
                    <option value="{{ $country }}" @selected(old('country', app()->getLocale() === 'id' ? 'Indonesia' : null) === $country)>{{ $country }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="{{ $field('website') }}">{{ __('site.audit_form.website') }}</label>
            <input id="{{ $field('website') }}" name="website" type="url" maxlength="190" inputmode="url" placeholder="https://"
                   value="{{ old('website') }}" @class(['form-control', 'is-invalid' => $errors->audit->has('website')])>
        </div>
        <div class="col-12">
            <label class="form-label" for="{{ $field('email') }}">{{ __('site.audit_form.email') }}</label>
            <input id="{{ $field('email') }}" name="email" type="email" required maxlength="190" autocomplete="email"
                   value="{{ old('email') }}" @class(['form-control', 'is-invalid' => $errors->audit->has('email')])>
        </div>

        {{-- Honeypot: hidden from people, filled by bots. --}}
        <div class="cv-hp" aria-hidden="true">
            <label for="{{ $field('fax') }}">Fax</label>
            <input id="{{ $field('fax') }}" name="fax" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" name="consent" id="{{ $field('consent') }}" @checked(old('consent'))>
                <label class="form-check-label small" for="{{ $field('consent') }}">{{ __('site.audit_form.consent', ['app' => config('app.name')]) }}</label>
            </div>
        </div>
        <div class="col-12">
            <x-site.turnstile bag="audit" />
        </div>
        <div class="col-12 d-grid">
            <button type="submit" class="btn btn-primary">{{ __('site.audit_form.submit') }}</button>
        </div>
        <p class="small text-muted mb-0">
            {!! __('site.audit_form.privacy', ['privacy' => '<a href="'.e(\App\Services\Site\PublicPages::url('privacy')).'">'.e(__('site.audit_form.privacy_link')).'</a>']) !!}
        </p>
    </div>
</form>

@if (session('audit_waitlisted'))
    <div class="alert alert-success py-2 small mt-2" role="status">{{ session('audit_waitlisted') }}</div>
@elseif (session('audit_full'))
    {{-- Fully booked today: offer an email when slots open. --}}
    <form method="POST" action="{{ route('audit.waitlist') }}" class="mt-2 small" novalidate>
        @csrf
        <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
        <input type="hidden" name="email" value="{{ old('email') }}">
        <div class="cv-hp" aria-hidden="true"><input name="fax" type="text" tabindex="-1" autocomplete="off"></div>
        <p class="mb-1">{{ __('audit.errors.waitlist_offer') }}</p>
        <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('audit.errors.waitlist_button') }}</button>
    </form>
@endif
