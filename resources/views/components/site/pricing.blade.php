@props(['plans'])
{{-- Plans from PlanCatalog. Monthly/annual and USD/IDR toggles switch the visible prices (public.js). --}}
@php
    use App\Services\Plans\PlanCatalog;

    $hasIdr = $plans->hasIdr();
    $currency = $hasIdr && app()->getLocale() === 'id' ? 'idr' : 'usd';
    $months = $plans->annualMonths();
    $discounts = $plans->discounts();
@endphp

<div data-pricing data-period="monthly" data-currency="{{ $currency }}">
    <div class="d-flex flex-wrap justify-content-center align-items-center gap-3 mb-4">
        <div class="cv-pricing-toggle" role="group" aria-label="{{ __('site.pricing.monthly') }} / {{ __('site.pricing.annual') }}">
            <button type="button" data-pricing-set="period:monthly" aria-pressed="true">{{ __('site.pricing.monthly') }}</button>
            <button type="button" data-pricing-set="period:annual" aria-pressed="false">{{ __('site.pricing.annual') }}</button>
        </div>
        @if ($hasIdr)
            <div class="cv-pricing-toggle" role="group" aria-label="{{ __('site.pricing.currency') }}">
                <button type="button" data-pricing-set="currency:idr" aria-pressed="{{ $currency === 'idr' ? 'true' : 'false' }}">IDR</button>
                <button type="button" data-pricing-set="currency:usd" aria-pressed="{{ $currency === 'usd' ? 'true' : 'false' }}">USD</button>
            </div>
        @endif
        <span class="small text-muted">{{ __('site.pricing.annual_note', ['months' => 12, 'paid' => $months]) }}</span>
    </div>

    <div class="row g-4 justify-content-center">
        @foreach ($plans->plans() as $plan)
            @php($limits = $plan['limits'])
            <div class="col-md-6 col-lg-5">
                <div @class(['cv-plan', 'is-featured' => $plan['key'] === 'pro'])>
                    <div class="d-flex justify-content-between align-items-start">
                        <h3>{{ $plan['name'] }}</h3>
                        @if ($plan['key'] === 'pro')
                            <span class="badge text-bg-primary">{{ __('site.pricing.popular') }}</span>
                        @endif
                    </div>

                    @foreach (['monthly', 'annual'] as $period)
                        @foreach (['usd', 'idr'] as $cur)
                            <div data-show="{{ $period }}-{{ $cur }}">
                                <div class="price">{{ PlanCatalog::format($plan[$period][$cur], $cur) }}</div>
                                <div class="small text-muted">{{ __($period === 'monthly' ? 'site.pricing.per_month' : 'site.pricing.per_year') }}</div>
                            </div>
                        @endforeach
                    @endforeach

                    <a href="{{ route('register') }}" @class(['btn w-100 my-3', 'btn-primary' => $plan['key'] === 'pro', 'btn-outline-primary' => $plan['key'] !== 'pro'])>{{ __('site.pricing.cta') }}</a>

                    <p class="fw-semibold mb-2">{{ __('site.pricing.includes') }}</p>
                    <ul>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_prompts', ['count' => $limits->promptsPerHotel]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_competitors', ['count' => $limits->competitors]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_languages', ['count' => $limits->languages]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_scans', ['count' => $limits->scansPerMonth]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_manual', ['count' => $limits->manualScansPerMonth]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_content', ['count' => $limits->contentGenerationsPerMonth]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_engines', ['engines' => implode(', ', $plan['engines'])]) }}</li>
                        <li><x-site.icon name="check" /> {{ __('site.pricing.feature_insights') }}</li>
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    <div class="text-center mt-4">
        <h3 class="h5">{{ __('site.pricing.groups_title') }}</h3>
        <p class="mb-2">
            {{ __('site.pricing.groups_text', [
                'group' => $discounts['group']['percent'] ?? 0, 'groupMin' => $discounts['group']['min_hotels'] ?? 0,
                'agency' => $discounts['agency']['percent'] ?? 0, 'agencyMin' => $discounts['agency']['min_hotels'] ?? 0,
            ]) }}
        </p>
        <a href="{{ \App\Services\Site\PublicPages::url('contact') }}">{{ __('site.pricing.groups_cta') }}</a>
    </div>
</div>
