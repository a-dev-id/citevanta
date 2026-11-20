@php($p = app(\App\Services\Site\SiteContent::class)->params())

<x-layouts::public :title="__('site.meta.contact_title', $p)" :description="__('site.meta.contact_description', $p)" page="contact">
    <section class="cv-section">
        <div class="container" style="max-width: 720px">
            <h1 class="cv-section-title">{{ __('site.contact.title') }}</h1>
            <p class="cv-section-lead">{{ __('site.contact.lead', $p) }}</p>

            @if (session('sent'))
                <div class="alert alert-success" role="status">{{ __('site.contact.sent') }}</div>
            @else
                <form method="POST" action="{{ route(\App\Services\Site\PublicPages::routeName('contact.send', app()->getLocale())) }}" novalidate>
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="contact-name">{{ __('site.contact.name') }}</label>
                            <input id="contact-name" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name"
                                   @class(['form-control', 'is-invalid' => $errors->has('name')])>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="contact-email">{{ __('site.contact.email') }}</label>
                            <input id="contact-email" name="email" type="email" required maxlength="190" value="{{ old('email') }}" autocomplete="email"
                                   @class(['form-control', 'is-invalid' => $errors->has('email')])>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="contact-company">{{ __('site.contact.company') }}</label>
                            <input id="contact-company" name="company" maxlength="190" value="{{ old('company') }}" autocomplete="organization" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="contact-message">{{ __('site.contact.message') }}</label>
                            <textarea id="contact-message" name="message" rows="6" required maxlength="5000"
                                      @class(['form-control', 'is-invalid' => $errors->has('message')])>{{ old('message') }}</textarea>
                            @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="cv-hp" aria-hidden="true">
                            <label for="contact-fax">Fax</label>
                            <input id="contact-fax" name="fax" type="text" tabindex="-1" autocomplete="off">
                        </div>
                        <div class="col-12">
                            <x-site.turnstile />
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('site.contact.submit') }}</button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </section>
</x-layouts::public>
