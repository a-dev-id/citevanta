@php
    $money = fn ($value) => trim($currency.' '.number_format((float) $value, 0));
    $change = function ($now, $before) use ($period) {
        if ($before === null || (float) $before === 0.0) {
            return null;
        }

        $percent = ((float) $now - (float) $before) / (float) $before * 100;

        return ($percent >= 0 ? '+' : '').number_format($percent, 0).'% '.$period->compareShortName();
    };
@endphp

<x-layouts::app title="Reports" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Reports' => null]">
    <x-report-toolbar :period="$period" :hotel="$hotel" :tab="$tab" />

    @if ($empty)
        <x-card :collapsible="false">
            <x-empty-state :icon="$empty['state'] === 'syncing' ? 'fa-rotate' : 'fa-plug-circle-exclamation'" :text="$empty['title']">
                <p class="text-muted">{{ $empty['text'] }}</p>
                @if ($empty['action'])
                    <a href="{{ $empty['url'] }}" class="btn btn-primary">{{ $empty['action'] }}</a>
                @endif
            </x-empty-state>
        </x-card>
    @else
        @foreach ($warnings as $warning)
            <div @class(['alert d-flex gap-2', 'alert-danger' => $warning['status'] === 'red', 'alert-warning' => $warning['status'] !== 'red']) role="alert">
                <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
                <div>{{ $warning['message'] }} <a href="{{ route('settings.google') }}" class="alert-link">Tracking check</a></div>
            </div>
        @endforeach

        @if ($dataWarning ?? null)
            <div class="alert alert-warning d-flex gap-2" role="alert" id="data-check-warning">
                <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
                <div>{{ $dataWarning }}</div>
            </div>
        @endif

        @isset($completeThrough)
            <div class="alert alert-info d-flex gap-2" role="status">
                <i class="fa-solid fa-rotate mt-1" aria-hidden="true"></i>
                <div>Data complete through <strong>{{ $completeThrough->format('j M Y') }}</strong>. Later days are still being copied from Google; they are blank in the charts, and the totals and tables count only the days copied so far.</div>
            </div>
        @endisset

        @if ($findings !== [])
            <x-card title="What this means" subtitle="Findings from your data, with where to act" :collapsible="false" class="mb-3">
                <ul class="list-unstyled mb-0">
                    @foreach ($findings as $finding)
                        <li class="d-flex flex-wrap align-items-start gap-2 py-2 border-bottom">
                            <i @class(['fa-solid mt-1', 'fa-triangle-exclamation text-warning' => $finding['level'] === 'warning', 'fa-circle-check text-success' => $finding['level'] === 'success', 'fa-circle-info text-primary' => $finding['level'] === 'info']) aria-hidden="true"></i>
                            <span class="flex-grow-1" style="min-width: 16rem">{{ $finding['text'] }}</span>
                            <span class="d-flex gap-2">
                                @foreach ($finding['actions'] as $action)
                                    <a href="{{ $action['url'] }}" class="btn btn-sm btn-outline-primary">{{ $action['label'] }}</a>
                                @endforeach
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @if ($tab === 'ai')
            @php($now = $summary['current'])
            @php($before = $summary['previous'])

            <div class="row g-3 mb-3" id="ai-summary">
                @foreach ([
                    ['AI sessions', number_format($now['ai_sessions']), $change($now['ai_sessions'], $before['ai_sessions'] ?? null), number_format($now['session_share'] * 100, 1).'% of all sessions ('.number_format($now['all_sessions']).')'],
                    ['AI purchases', number_format($now['ai_purchases']), $change($now['ai_purchases'], $before['ai_purchases'] ?? null), number_format($now['purchase_share'] * 100, 1).'% of all purchases ('.number_format($now['all_purchases']).')'],
                    ['AI revenue', $money($now['ai_revenue']), $change($now['ai_revenue'], $before['ai_revenue'] ?? null), number_format($now['all_revenue'] > 0 ? $now['ai_revenue'] / $now['all_revenue'] * 100 : 0, 1).'% of all revenue ('.$money($now['all_revenue']).')'],
                ] as [$label, $value, $delta, $sub])
                    <div class="col-md-4">
                        <div class="card cv-card h-100"><div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="fs-3 fw-semibold">{{ $value }}</div>
                            @if ($delta)<div class="small">{{ $delta }}</div>@endif
                            <div class="small text-muted">{{ $sub }}</div>
                        </div></div>
                    </div>
                @endforeach
            </div>

            <p class="small mb-3" id="script-seen">
                <i class="fa-solid fa-code me-1" aria-hidden="true"></i>
                @if ($scriptSeen)
                    <strong>Seen by the website script:</strong> {{ number_format($scriptSeen['ai']) }} visits from AI assistants @if ($scriptSeen['prev_ai'] !== null)<span class="text-muted">({{ $change($scriptSeen['ai'], $scriptSeen['prev_ai']) ?? 'no change figure' }})</span>@endif
                    and {{ number_format($scriptSeen['clicks']) }} booking clicks @if ($scriptSeen['prev_clicks'] !== null)<span class="text-muted">({{ $change($scriptSeen['clicks'], $scriptSeen['prev_clicks']) ?? 'no change figure' }})</span>@endif
                    in this period. <a href="{{ route('settings.website-script') }}">Website script</a>
                @else
                    <span class="text-muted">Add the website script to also count AI visits and booking clicks on your own website: <a href="{{ route('settings.website-script') }}">Settings &gt; Website script</a>.</span>
                @endif
            </p>

            <div class="row g-3 mb-3">
                <div class="col-xl-6">
                    <x-card title="AI sessions" :collapsible="false">
                        <x-chart id="cv-chart-ai-sessions" :chart="$charts['sessions']" column="Period" label="Sessions from AI assistants over the selected period" />
                    </x-card>
                </div>
                <div class="col-xl-6">
                    <x-card title="AI purchases" :collapsible="false">
                        <x-chart id="cv-chart-ai-purchases" type="bar" :chart="$charts['purchases']" column="Period" label="Purchases from AI assistants over the selected period" />
                    </x-card>
                </div>
            </div>

            @foreach ($tables as $table)
                <div class="mb-3"><x-report-table :table="$table" :hotel="$hotel" :period="$period" :currency="$currency" /></div>
            @endforeach

            <h2 class="h5 mt-4" data-tour="searches-by-date">Searches by date</h2>
            @if ($bookingSetup)
                <x-card :collapsible="false" class="mb-3">
                    <x-empty-state icon="fa-calendar-days" :text="$bookingSetup['title']">
                        <p class="text-muted">{{ $bookingSetup['text'] }}</p>
                        @if ($bookingSetup['action'])<a href="{{ $bookingSetup['url'] }}" class="btn btn-primary">{{ $bookingSetup['action'] }}</a>@endif
                        @if (! empty($bookingSetup['developer']))
                            <details class="mt-3 text-start"><summary>Message for your web developer</summary><pre class="small text-wrap mt-2" data-copy-source>{{ $bookingSetup['developer'] }}</pre></details>
                        @endif
                    </x-empty-state>
                </x-card>
            @else
                {{-- Country: filters the calendar, the stay tables and the CSV of this section. --}}
                <form method="GET" action="{{ route('hotels.reports', $hotel) }}" class="d-flex flex-wrap align-items-center gap-2 mb-3" id="country-form">
                    <input type="hidden" name="report" value="ai">
                    @foreach ($period->query() as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    @if (request()->query('size'))<input type="hidden" name="size" value="{{ request()->query('size') }}">@endif
                    <label for="country" class="small text-muted mb-0">Country</label>
                    <select name="country" id="country" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="">All countries</option>
                        @foreach ($demandCountries as $option)
                            <option value="{{ $option['value'] }}" @selected($demandCountry === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                        @if ($demandCountry !== '' && ! collect($demandCountries)->contains('value', $demandCountry))
                            <option value="{{ $demandCountry }}" selected>{{ $demandCountry === 'unknown' ? 'Unknown' : $demandCountry }}</option>
                        @endif
                    </select>
                    <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
                </form>

                <div x-data="{ tab: 'popular' }" id="demand-section">
                    <div class="cv-tabs mb-3" role="tablist" aria-label="Demand view">
                        <button type="button" role="tab" id="tab-popular" :aria-selected="tab === 'popular' ? 'true' : 'false'" :class="{ 'is-active': tab === 'popular' }" @click="tab = 'popular'">Popular dates</button>
                        <button type="button" role="tab" id="tab-every" :aria-selected="tab === 'every' ? 'true' : 'false'" :class="{ 'is-active': tab === 'every' }" @click="tab = 'every'">Every date <span class="cv-tabs__count">{{ $demandDateCount }}</span></button>
                    </div>

                    <div x-show="tab === 'popular'" role="tabpanel" aria-labelledby="tab-popular">
                        <div class="alert alert-light border small d-flex gap-2" role="note">
                            <i class="fa-solid fa-circle-info mt-1" aria-hidden="true"></i>
                            <span>The stay dates guests searched for in your booking engine, and the ones they started to book. These are searches, not bookings. Each night is shaded by how often it was searched; hold the mouse over a night to see the stays behind it. This is GA4 data from all visitors, not only from AI assistants.</span>
                        </div>
                        <x-card :collapsible="false" class="mb-3">
                            <x-demand-calendar :calendar="$demandCalendar" />
                            @if ($demandStays)
                                <hr class="my-4">
                                <x-stay-histogram :stays="$demandStays" />
                            @endif
                        </x-card>
                    </div>

                    <div x-show="tab === 'every'" x-cloak role="tabpanel" aria-labelledby="tab-every">
                        <div class="mb-3"><x-report-table :table="$demandTables['demand-checkin']" :hotel="$hotel" :period="$period" /></div>
                    </div>

                </div>

                @if ($countryBars !== [])
                    <h3 class="cv-eyebrow mt-4">Where AI visitors come from</h3>
                    <x-card :collapsible="false" class="mb-3">
                        <x-bar-list :rows="$countryBars" title="Countries" :total="$summary['current']['ai_sessions']" unit="visits" />
                    </x-card>
                @endif
            @endif
        @else
            <div class="row g-3 mb-3" id="search-summary">
                @foreach ([['Clicks', number_format($totals['clicks']), $change($totals['clicks'], $previousTotals['clicks'] ?? null)], ['Impressions', number_format($totals['impressions']), $change($totals['impressions'], $previousTotals['impressions'] ?? null)], ['Click rate', number_format($totals['ctr'] * 100, 1).'%', $change($totals['ctr'], $previousTotals['ctr'] ?? null)], ['Average position', number_format($totals['position'], 1), $change($totals['position'], $previousTotals['position'] ?? null)]] as [$label, $value, $delta])
                    <div class="col-6 col-md-3"><div class="card cv-card h-100"><div class="card-body">
                        <div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-semibold">{{ $value }}</div>
                        @if ($delta)<div class="small">{{ $delta }}</div>@endif
                    </div></div></div>
                @endforeach
            </div>

            <div class="alert alert-light border small" role="note"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>{{ $aiOverviewsNote }}</div>

            @foreach ($tables as $table)
                <div class="mb-3"><x-report-table :table="$table" :hotel="$hotel" :period="$period" /></div>
            @endforeach
        @endif
    @endif
</x-layouts::app>
