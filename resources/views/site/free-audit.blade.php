@php($p = app(\App\Services\Site\SiteContent::class)->params())

<x-layouts::public :title="__('site.meta.audit_title', $p)" :description="__('site.meta.audit_description', $p)" page="free-audit" :json-ld="$jsonLd">
    <section class="cv-section">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-6">
                    <h1 class="cv-section-title">{{ __('site.audit_page.title') }}</h1>
                    <p class="cv-lead">{{ __('site.audit_page.lead') }}</p>
                    <h2 class="h5 mt-4">{{ __('site.audit_page.what_title') }}</h2>
                    <ul class="list-unstyled">
                        @foreach (trans('site.audit_page.what') as $item)
                            <li class="d-flex gap-2 mb-2"><x-site.icon name="check" class="text-success mt-1" /> {{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="cv-audit-card">
                        <h2 class="mb-1">{{ __('site.hero.form_title') }}</h2>
                        <p class="small text-muted mb-3">{{ __('site.hero.form_note') }}</p>
                        <x-site.audit-form id-prefix="page-audit" />
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts::public>
