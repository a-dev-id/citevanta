@php($p = app(\App\Services\Site\SiteContent::class)->params())

<x-layouts::public :title="__('site.meta.pricing_title', $p)" :description="__('site.meta.pricing_description', $p)" page="pricing" :json-ld="$jsonLd">
    <section class="cv-section">
        <div class="container">
            <div class="text-center">
                <h1 class="cv-section-title">{{ __('site.pricing.title') }}</h1>
                <p class="cv-section-lead mx-auto">{{ __('site.pricing.lead', $p) }}</p>
            </div>
            <x-site.pricing :plans="$plans" />
        </div>
    </section>

    <section class="cv-section cv-section--soft">
        <div class="container" style="max-width: 860px">
            <x-site.faq :items="$faq" id="faq-pricing" :title="__('site.pricing.faq_title')" />
        </div>
    </section>
</x-layouts::public>
