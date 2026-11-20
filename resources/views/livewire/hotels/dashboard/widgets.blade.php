@php
    $m = $d->metrics;
    $label = $d->previous ? 'vs previous scan' : null;
@endphp

<div class="row gx-3">
    <div class="col-sm-6 col-xl-3">
        <x-widget-summary title="Overall visibility" icon="fa-eye" variant="primary"
                          :value="\App\Services\Dashboard\DashboardService::pct($m->visibility)"
                          :change="$d->changes['visibility']" change-label="vs previous scan" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-widget-summary title="Average position" icon="fa-ranking-star" variant="info"
                          :value="$m->averagePosition === null ? '—' : '#'.number_format($m->averagePosition, 1)"
                          :change="$d->changes['position']" change-unit="places" change-label="vs previous scan" :lower-is-better="true" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-widget-summary title="Positive sentiment" icon="fa-face-smile" variant="success"
                          :value="\App\Services\Dashboard\DashboardService::pct($m->positivePct)"
                          :change="$d->changes['positive']" change-label="vs previous scan" />
    </div>
    <div class="col-sm-6 col-xl-3">
        <x-widget-summary title="Cited sources" icon="fa-link" variant="warning"
                          :value="(string) $m->citedSources"
                          :change="$d->changes['sources']" change-unit="" :precision="0" change-label="vs previous scan" />
    </div>
</div>
<p class="small text-muted mt-n2 mb-3">
    {{ $m->mentions }} of {{ $m->answers }} answers mention {{ $d->hotel->name }}.
    Positive sentiment is the share of those mentions that were positive.
</p>
