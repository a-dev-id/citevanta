@php($p = app(\App\Services\Site\SiteContent::class)->params())

<x-layouts::public :title="__('site.meta.'.$page.'_title', $p)" :description="__('site.meta.'.$page.'_description', $p)" :page="$page">
    <section class="cv-section">
        <div class="container cv-legal">
            <h1 class="cv-section-title">{{ __('site.legal.'.$page.'_title') }}</h1>
            <div class="alert alert-warning small" role="note">{{ __('site.legal.review_note') }}</div>
            @foreach (trans('site.legal.'.$page) as $section)
                <h2>{{ $section['h'] }}</h2>
                <p>{{ strtr($section['p'], [':app' => $p['app']]) }}</p>
            @endforeach
            <p class="small text-muted mt-4">{{ __('site.legal.updated', ['date' => '20 November 2026']) }}</p>
        </div>
    </section>
</x-layouts::public>
