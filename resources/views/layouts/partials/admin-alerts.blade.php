{{-- Admins only: integrations that need attention. --}}
@if (auth()->user()?->is_admin && app(\App\Services\Google\GoogleAgent::class)->isBroken())
    @if ($wrap ?? false)<div class="cv-content pb-0">@endif
    <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="alert">
        <i class="fa-solid fa-plug-circle-xmark" aria-hidden="true"></i>
        <span class="flex-grow-1">The Google connection stopped working: GA4 and Search Console are not updated.</span>
        <a href="{{ route('admin.integrations.google') }}" class="btn btn-sm btn-outline-dark">Reconnect Google</a>
    </div>
    @if ($wrap ?? false)</div>@endif
@endif
