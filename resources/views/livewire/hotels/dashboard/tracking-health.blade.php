{{-- Private plan: GA4 / Search Console tracking health (Settings > Connect Google). --}}
@php($link = $hotel->googleLink)
@if ($link && $link->health)
    @php($counts = collect($link->health)->countBy('status'))
    <div @class(['alert d-flex flex-wrap align-items-center gap-2 py-2', 'alert-success' => ! $counts->has('red') && ! $counts->has('amber'), 'alert-warning' => ! $counts->has('red') && $counts->has('amber'), 'alert-danger' => $counts->has('red')]) role="status">
        <i class="fa-solid fa-stethoscope" aria-hidden="true"></i>
        <span class="flex-grow-1">
            Tracking health: {{ $counts->get('green', 0) }} OK
            @if ($counts->get('amber')) · {{ $counts->get('amber') }} to check @endif
            @if ($counts->get('red')) · {{ $counts->get('red') }} to fix @endif
        </span>
        @if (auth()->user()->ownsTeam($hotel->team))
            <a href="{{ route('settings.google') }}" class="btn btn-sm btn-outline-dark">Details</a>
        @endif
    </div>
@endif
