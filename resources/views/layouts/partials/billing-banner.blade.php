@php($banner = app(\App\Services\Billing\BillingBanner::class)->for(auth()->user(), auth()->user()?->currentTeam))
@if ($banner)
    @if ($wrap ?? false)<div class="cv-content pb-0">@endif
    <div class="alert alert-{{ $banner['variant'] }} d-flex flex-wrap align-items-center gap-2" role="status" data-tour="trial-banner">
        <i class="fa-solid {{ $banner['icon'] }}" aria-hidden="true"></i>
        <span class="flex-grow-1">{{ $banner['text'] }}</span>
        @if ($banner['link'] && ! request()->routeIs('settings.billing'))
            <a href="{{ $banner['link'] }}" class="btn btn-sm btn-outline-dark">{{ $banner['label'] ?? 'Billing' }}</a>
        @endif
    </div>
    @if ($wrap ?? false)</div>@endif
@endif
