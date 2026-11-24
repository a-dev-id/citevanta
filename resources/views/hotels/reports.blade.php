@php
    $money = fn ($value) => trim($currency.' '.number_format((float) $value, 0));
    $change = function ($now, $before) {
        if ($before === null || (float) $before === 0.0) {
            return null;
        }

        $percent = ((float) $now - (float) $before) / (float) $before * 100;

        return ($percent >= 0 ? '+' : '').number_format($percent, 0).'% vs last year';
    };
@endphp

<x-layouts::app title="Reports" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Reports' => null]">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <ul class="nav nav-pills" aria-label="Report">
            <li class="nav-item"><a @class(['nav-link', 'active' => $tab === 'ai']) href="{{ route('hotels.reports', [$hotel, 'range' => $period->key, 'report' => 'ai']) }}">AI traffic and bookings</a></li>
            <li class="nav-item"><a @class(['nav-link', 'active' => $tab === 'search']) href="{{ route('hotels.reports', [$hotel, 'range' => $period->key, 'report' => 'search']) }}">Google Search</a></li>
        </ul>

        <div class="btn-group" role="group" aria-label="Date range">
            @foreach (['28d' => 'Last 28 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months'] as $key => $label)
                <a href="{{ route('hotels.reports', [$hotel, 'range' => $key, 'report' => $tab]) }}" @class(['btn btn-sm', 'btn-primary' => $period->key === $key, 'btn-outline-secondary' => $period->key !== $key])
                   @if ($period->key === $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <p class="small text-muted"><i class="fa-regular fa-calendar me-1" aria-hidden="true"></i>{{ $period->label() }}
        @if ($hotel->googleLink?->last_synced_at) · data synced {{ $hotel->googleLink->last_synced_at->diffForHumans() }} @endif
    </p>

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

            @if ($before === null)
                <p class="small text-muted"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>The same period last year is shown when 13 months of data exist. It is not available for this range yet.</p>
            @endif

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

            <h2 class="h5 mt-4">Demand by date</h2>
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
                <x-card title="Check-in demand by week" subtitle="What guests looked at and started booking, by the date they want to arrive" :collapsible="false" class="mb-3">
                    <x-chart id="cv-chart-demand" type="bar" :stacked="true" :chart="$demandChart" column="Check-in week" label="Demand by check-in week" />
                </x-card>
                <div class="mb-3"><x-report-table :table="$demandTables['demand-checkin']" :rows="$demandTop" :hotel="$hotel" :period="$period" /></div>
                <div class="mb-3"><x-report-table :table="$demandTables['demand-stay']" :hotel="$hotel" :period="$period" /></div>
            @endif
        @else
            <div class="row g-3 mb-3" id="search-summary">
                @foreach ([['Clicks', number_format($totals['clicks'])], ['Impressions', number_format($totals['impressions'])], ['Click rate', number_format($totals['ctr'] * 100, 1).'%'], ['Average position', number_format($totals['position'], 1)]] as [$label, $value])
                    <div class="col-6 col-md-3"><div class="card cv-card h-100"><div class="card-body">
                        <div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-semibold">{{ $value }}</div>
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
