@php
    use App\Services\Dashboard\DashboardService as DS;

    $hotel = $d->hotel;
    $tz = $hotel->team?->timezoneName() ?? config('citevanta.default_timezone');
    $fmt = fn ($date) => $date ? $date->copy()->setTimezone($tz)->format('j M Y') : '—';
    $crumbs = ['Hotels' => route('hotels.index'), $hotel->name => $d->isLatest ? null : route('hotels.show', $hotel)];
    if (! $d->isLatest && $d->scan) {
        $crumbs['Scan #'.$d->scan->id] = null;
    }
@endphp

<div>
    <x-page-header :title="$hotel->name" :breadcrumbs="$crumbs">
        <span class="d-none d-xl-inline text-nowrap">Last scan: {{ $fmt($d->lastCompletedAt) }}</span>
        <span class="d-none d-xl-inline text-nowrap">Next scheduled: {{ $d->nextScheduledAt ? $fmt($d->nextScheduledAt) : 'Not scheduled' }}</span>
        <span class="d-inline-flex flex-column align-items-end">
            <button type="button" class="btn btn-sm btn-primary text-nowrap"
                    wire:click="scanNow"
                    @if (auth()->user()->is_admin)
                        wire:confirm="Admin scan of {{ $hotel->name }}: estimated cost ${{ number_format(app(\App\Services\Scanning\ManualScanGate::class)->estimate($hotel), 2) }}, charged to the admin AI budget. Start it?"
                    @else
                        wire:confirm="Start a scan of {{ $hotel->name }} now? It uses one of your manual scans this month."
                    @endif
                    wire:loading.attr="disabled" wire:target="scanNow"
                    @disabled(! $d->manualScan->allowed)
                    @if (! $d->manualScan->allowed) aria-describedby="scan-now-reason" @endif>
                <span wire:loading wire:target="scanNow" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i class="fa-solid fa-bolt me-1" aria-hidden="true" wire:loading.remove wire:target="scanNow"></i> Scan now
            </button>
        </span>
    </x-page-header>

    <div class="cv-content">
        {{-- Scan now status line: allowance or why it is disabled. --}}
        <p @class(['small mb-3 text-end', 'text-muted' => $d->manualScan->allowed]) id="scan-now-reason">
            @if ($d->manualScan->allowed)
                <i class="fa-solid fa-bolt me-1" aria-hidden="true"></i>{{ $d->allowanceText }}
            @else
                <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Scan now unavailable: {{ $d->manualScan->reason }}
            @endif
        </p>

        @include('livewire.hotels.dashboard.tracking-health')

        @if (session('success'))
            <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ session('success') }}</div>
        @endif
        @if ($notice)
            <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
        @endif
        @if ($error)
            <div class="alert alert-warning" role="alert"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i> {{ $error }}</div>
        @endif

        <livewire:hotels.scan-status :hotel-id="$hotel->id" wire:key="scan-status-{{ $hotel->id }}" />

        @if (! $d->isLatest && $d->scan)
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                You are viewing scan #{{ $d->scan->id }} from {{ $fmt($d->scan->finished_at) }}.
                <a href="{{ route('hotels.show', $hotel) }}" class="alert-link">View latest</a>
            </div>
        @endif

        @if ($d->failedScan)
            @include('livewire.hotels.dashboard.failed', ['scan' => $d->failedScan])
        @endif

        @if ($d->scan === null)
            @unless ($d->failedScan)
                <x-card title="No results yet" :collapsible="false">
                    @if ($d->activeScan)
                        <x-empty-state icon="fa-satellite-dish" text="Your first scan is running. Results appear here when it finishes." />
                    @else
                        <x-empty-state icon="fa-satellite-dish" text="No scans yet. Run your first scan to see how AI assistants recommend your hotel.">
                            <button type="button" class="btn btn-primary" wire:click="scanNow" @disabled(! $d->manualScan->allowed)
                                    @if (auth()->user()->is_admin) wire:confirm="Admin scan: estimated cost ${{ number_format(app(\App\Services\Scanning\ManualScanGate::class)->estimate($hotel), 2) }}, charged to the admin AI budget. Start it?" @endif>
                                <i class="fa-solid fa-bolt me-1" aria-hidden="true"></i> Run your first scan
                            </button>
                        </x-empty-state>
                    @endif
                </x-card>
            @endunless
        @else
            @include('livewire.hotels.dashboard.engine-filter')
            @include('livewire.hotels.dashboard.widgets')

            <div class="row gx-3">
                <div class="col-xl-6">
                    @include('livewire.hotels.dashboard.recommendations')
                </div>
                <div class="col-xl-6">
                    @include('livewire.hotels.dashboard.accuracy')
                </div>
            </div>

            <div class="row gx-3">
                <div class="col-xl-7">
                    @include('livewire.hotels.dashboard.personas')
                </div>
                <div class="col-xl-5">
                    @include('livewire.hotels.dashboard.competitors')
                </div>
            </div>

            <div class="row gx-3">
                <div class="col-xl-7">
                    @include('livewire.hotels.dashboard.visibility-trend')
                </div>
                <div class="col-xl-5">
                    @include('livewire.hotels.dashboard.links')
                </div>
            </div>

            @include('livewire.hotels.dashboard.trends')

            @include('livewire.hotels.dashboard.domains')

            @include('livewire.hotels.dashboard.competitor-reasons')
            @include('livewire.hotels.dashboard.prompts')
        @endif

        @include('livewire.hotels.dashboard.history')
    </div>
</div>
