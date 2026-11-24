@props([
    // App\Services\Reports\ReportPeriod
    'period',
    'hotel',
    'tab',
])

@php
    use App\Services\Reports\ReportPeriod;

    $latest = ($period->latest ?? \Illuminate\Support\Carbon::yesterday())->min(\Illuminate\Support\Carbon::yesterday());
    $earliest = $period->earliest ?? $period->from->copy()->subYears(2);

    // What stays when the range changes: the sort and the page size, never the page.
    $keep = array_filter(['sort' => request()->query('sort'), 'size' => request()->query('size')]);
    $route = fn (array $query) => route('hotels.reports', [$hotel] + $query + ['report' => $tab] + $keep);
    $matched = $period->matchedPreset();

    $presets = [];
    foreach (ReportPeriod::PRESETS as $key => $label) {
        [$from, $to] = ReportPeriod::presetRange($key);
        $from = $from->max($earliest);
        $presets[] = ['key' => $key, 'label' => $label, 'range' => $from->format('j M').' - '.$to->format('j M Y'),
            'url' => $route(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'compare' => $period->compare, 'dow' => $period->matchWeekday ? 1 : null] + []),
            'active' => $matched === $key];
    }

    $config = [
        'min' => $earliest->toDateString(), 'max' => $latest->toDateString(), 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString(),
        'url' => route('hotels.reports', $hotel),
        'params' => array_filter(['report' => $tab, 'compare' => $period->compare, 'dow' => $period->matchWeekday ? '1' : null] + $keep, fn ($value) => $value !== null && ! is_array($value)),
        'sort' => request()->query('sort', []),
    ];
@endphp

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-2" id="report-toolbar">
    <ul class="nav nav-pills" aria-label="Report">
        <li class="nav-item"><a @class(['nav-link', 'active' => $tab === 'ai']) href="{{ route('hotels.reports', [$hotel, 'report' => 'ai'] + $period->query() + $keep) }}">AI traffic and bookings</a></li>
        <li class="nav-item"><a @class(['nav-link', 'active' => $tab === 'search']) href="{{ route('hotels.reports', [$hotel, 'report' => 'search'] + $period->query() + $keep) }}">Google Search</a></li>
    </ul>

    <div class="d-flex flex-wrap align-items-start gap-2">
        {{-- Date range: presets, or a custom range on a two-month calendar. --}}
        <div class="cv-range" x-data="reportRange(@js($config))" @keydown.escape="open = false" @click.outside="open = false">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="range-button" :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true" @click="open = !open">
                <i class="fa-regular fa-calendar me-1" aria-hidden="true"></i>{{ $matched ? ReportPeriod::PRESETS[$matched] : 'Custom range' }}:
                {{ $period->from->format('j M Y') }} - {{ $period->to->format('j M Y') }}
                <i class="fa-solid fa-chevron-down ms-1 small" aria-hidden="true"></i>
            </button>

            <div class="cv-range__panel" x-show="open" x-cloak id="range-panel">
                <div class="cv-range__presets" role="list">
                    @foreach ($presets as $preset)
                        <a href="{{ $preset['url'] }}" role="listitem" @class(['cv-range__preset', 'is-active' => $preset['active']]) @if ($preset['active']) aria-current="true" @endif>
                            <span>{{ $preset['label'] }}</span><small class="text-muted">{{ $preset['range'] }}</small>
                        </a>
                    @endforeach
                    <button type="button" @class(['cv-range__preset', 'is-active' => ! $matched]) @click="custom = true"><span>Custom range</span></button>
                </div>

                <div class="cv-range__custom" x-show="custom" x-cloak id="range-custom">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <button type="button" class="btn btn-sm btn-light" :disabled="!canPrev" @click="view = addMonths(view, -1)" aria-label="Earlier months"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                        <strong class="small" x-text="from + ' - ' + to"></strong>
                        <button type="button" class="btn btn-sm btn-light" :disabled="!canNext" @click="view = addMonths(view, 1)" aria-label="Later months"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="cv-range__months">
                        <template x-for="month in months" :key="month.key">
                            <div class="cv-range__month">
                                <div class="cv-range__title" x-text="month.label"></div>
                                <div class="cv-range__grid" role="grid">
                                    <template x-for="name in ['Mo','Tu','We','Th','Fr','Sa','Su']"><span class="cv-range__dow" x-text="name"></span></template>
                                    <template x-for="n in month.blanks"><span></span></template>
                                    <template x-for="day in month.days" :key="day.key">
                                        <button type="button" class="cv-range__day" :disabled="day.disabled" :class="day.class" :data-date="day.key" @click="pick(day.key)" x-text="day.n"></button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p class="small text-muted mt-2 mb-2">Available: {{ $earliest->format('j M Y') }} (first synced day) to {{ $latest->format('j M Y') }} (last fully synced day).</p>
                    <button type="button" class="btn btn-sm btn-primary" id="range-apply" :disabled="picking" @click="apply()">Apply</button>
                </div>
            </div>
        </div>

        {{-- Comparison. --}}
        <form method="GET" action="{{ route('hotels.reports', $hotel) }}" class="d-flex flex-wrap align-items-center gap-2" id="compare-form" x-data="{ compare: @js($period->compare) }">
            <input type="hidden" name="report" value="{{ $tab }}">
            <input type="hidden" name="from" value="{{ $period->from->toDateString() }}">
            <input type="hidden" name="to" value="{{ $period->to->toDateString() }}">
            @foreach ($keep as $name => $value)
                @if (is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <label for="compare" class="small text-muted mb-0">Compare</label>
            <select name="compare" id="compare" class="form-select form-select-sm w-auto" x-model="compare" onchange="this.form.submit()">
                @foreach (ReportPeriod::COMPARE as $value => $label)
                    @php($why = $period->unavailable[$value] ?? null)
                    <option value="{{ $value }}" @selected($period->compare === $value) @disabled($why !== null)>{{ $label }}@if ($why) ({{ $why }})@endif</option>
                @endforeach
            </select>
            <div class="form-check mb-0" x-show="compare === 'year'" x-cloak>
                <input class="form-check-input" type="checkbox" name="dow" value="1" id="dow" @checked($period->matchWeekday) onchange="this.form.submit()">
                <label class="form-check-label small" for="dow">Match day of week</label>
            </div>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
        </form>
    </div>
</div>

<p class="small text-muted mb-2" id="range-summary"><i class="fa-regular fa-calendar me-1" aria-hidden="true"></i>{{ $period->label() }}
    @if ($period->hasComparison()) · compared with {{ $period->compareName() }}: {{ $period->compareLabel() }} @endif
    @if ($hotel->googleLink?->last_synced_at) · data synced {{ $hotel->googleLink->last_synced_at->diffForHumans() }} @endif
</p>
@if ($period->unavailable !== [])
    <p class="small text-muted mb-2" id="compare-note">
        @foreach ($period->unavailable as $mode => $why)
            <span class="me-3"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>{{ ReportPeriod::COMPARE[$mode] }} is not available for this range: {{ $why }}.</span>
        @endforeach
    </p>
@endif

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('reportRange', (cfg) => ({
                open: false,
                custom: false,
                from: cfg.from,
                to: cfg.to,
                picking: false,
                view: '',

                init() { this.view = this.addMonths(cfg.to.slice(0, 7), -1) < cfg.min.slice(0, 7) ? cfg.min.slice(0, 7) : this.addMonths(cfg.to.slice(0, 7), -1); },

                pad(n) { return String(n).padStart(2, '0'); },

                addMonths(ym, n) {
                    const [y, m] = ym.split('-').map(Number);
                    const d = new Date(Date.UTC(y, m - 1 + n, 1));
                    return d.getUTCFullYear() + '-' + this.pad(d.getUTCMonth() + 1);
                },

                get canPrev() { return this.view > cfg.min.slice(0, 7); },
                get canNext() { return this.addMonths(this.view, 1) < cfg.max.slice(0, 7); },

                get months() {
                    return [this.view, this.addMonths(this.view, 1)].map((ym) => {
                        const [y, m] = ym.split('-').map(Number);
                        const count = new Date(Date.UTC(y, m, 0)).getUTCDate();
                        const blanks = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
                        const days = Array.from({ length: count }, (_, i) => {
                            const key = ym + '-' + this.pad(i + 1);
                            const edge = key === this.from || key === this.to;
                            return {
                                key, n: i + 1, disabled: key < cfg.min || key > cfg.max,
                                class: { 'is-edge': edge, 'is-in': key > this.from && key < this.to },
                            };
                        });
                        const label = new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
                        return { key: ym, label, blanks, days };
                    });
                },

                // First click: the start. Second click: the end (earlier than the start swaps them).
                pick(key) {
                    if (!this.picking) { this.from = key; this.to = key; this.picking = true; return; }
                    if (key < this.from) { this.to = this.from; this.from = key; } else { this.to = key; }
                    this.picking = false;
                },

                apply() {
                    const params = new URLSearchParams(cfg.params);
                    params.set('from', this.from);
                    params.set('to', this.to);
                    Object.entries(cfg.sort || {}).forEach(([table, value]) => params.set('sort[' + table + ']', value));
                    window.location = cfg.url + '?' + params.toString();
                },
            }));
        });
    </script>
@endonce
