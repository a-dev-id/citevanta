@php
    $p = app(\App\Services\Site\SiteContent::class)->params();
    $pages = \App\Services\Site\PublicPages::class;
@endphp

<x-layouts::public :title="__('site.meta.home_title', $p)" :description="__('site.meta.home_description', $p)" page="home" :json-ld="$jsonLd">
    {{-- Hero with the free audit form --}}
    <section class="cv-hero">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <span class="cv-eyebrow">{{ __('site.hero.eyebrow') }}</span>
                    <h1 class="mb-3">{{ __('site.hero.title') }}</h1>
                    <p class="cv-lead mb-4">{{ __('site.hero.lead', $p) }}</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('register') }}" class="btn btn-primary">{{ __('site.nav.trial') }}</a>
                        <a href="{{ $pages::url('pricing') }}" class="btn btn-outline-light">{{ __('site.nav.pricing') }}</a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="cv-audit-card" id="free-audit">
                        <h2 class="mb-1">{{ __('site.hero.form_title') }}</h2>
                        <p class="small text-muted mb-3">{{ __('site.hero.form_note') }}</p>
                        <x-site.audit-form id-prefix="hero-audit" />
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- The problem --}}
    <section class="cv-section">
        <div class="container">
            <h2 class="cv-section-title">{{ __('site.problem.title') }}</h2>
            <div class="row g-4 mt-1">
                @foreach (trans('site.problem.points') as $point)
                    <div class="col-md-4">
                        <div class="cv-tile">
                            <h3>{{ $point['title'] }}</h3>
                            <p>{{ strtr($point['text'], [':app' => $p['app']]) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section class="cv-section cv-section--soft">
        <div class="container">
            <h2 class="cv-section-title">{{ __('site.how.title') }}</h2>
            <ol class="row g-4 mt-1 list-unstyled">
                @foreach (trans('site.how.steps') as $i => $step)
                    <li class="col-md-4">
                        <div class="cv-tile">
                            <span class="cv-step-number" aria-hidden="true">{{ $i + 1 }}</span>
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ strtr($step['text'], [':app' => $p['app']]) }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Features --}}
    <section class="cv-section" id="features">
        <div class="container">
            <h2 class="cv-section-title">{{ __('site.features.title') }}</h2>
            <div class="row g-4 mt-1">
                @foreach (trans('site.features.items') as $feature)
                    <div class="col-md-6 col-lg-4">
                        <div class="cv-tile">
                            <span class="cv-tile__icon"><x-site.icon :name="$feature['icon']" /></span>
                            <h3>{{ $feature['title'] }}</h3>
                            <p>{{ strtr($feature['text'], [':app' => $p['app']]) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Sample dashboard --}}
    <section class="cv-section cv-section--soft">
        <div class="container">
            <h2 class="cv-section-title">{{ __('site.sample.title') }}</h2>
            <p class="cv-section-lead">{{ __('site.sample.lead') }}</p>
            <x-site.dashboard-sample />
        </div>
    </section>

    {{-- Pricing --}}
    <section class="cv-section" id="pricing">
        <div class="container">
            <div class="text-center">
                <h2 class="cv-section-title">{{ __('site.pricing.title') }}</h2>
                <p class="cv-section-lead mx-auto">{{ __('site.pricing.lead', $p) }}</p>
            </div>
            <x-site.pricing :plans="$plans" />
        </div>
    </section>

    {{-- FAQ --}}
    <section class="cv-section cv-section--soft">
        <div class="container" style="max-width: 860px">
            <x-site.faq :items="$faq" id="faq-home" :title="__('site.faq.title')" />
        </div>
    </section>

    {{-- Final call to action --}}
    <section class="cv-section cv-section--dark text-center">
        <div class="container">
            <h2 class="cv-section-title">{{ __('site.cta.title') }}</h2>
            <p class="cv-section-lead mx-auto">{{ __('site.cta.text', $p) }}</p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <a href="{{ $pages::url('free-audit') }}" class="btn btn-primary">{{ __('site.cta.audit') }}</a>
                <a href="{{ route('register') }}" class="btn btn-outline-light">{{ __('site.cta.trial') }}</a>
            </div>
        </div>
    </section>
</x-layouts::public>
