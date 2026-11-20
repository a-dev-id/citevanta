<x-layouts::app title="Hotels" :breadcrumbs="['Hotels' => null]">
    <x-slot:pageActions>
        @if ($canAdd)
            <a href="{{ route('hotels.create') }}" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add hotel
            </a>
        @endif
    </x-slot:pageActions>

    @if (! $canAdd && $hotels->isNotEmpty())
        <div class="alert alert-info" role="status">
            <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
            Your plan includes {{ $limit }} {{ Str::plural('hotel', $limit) }}. You are using all of them.
        </div>
    @endif

    <x-card title="Your hotels" :flush="true" :collapsible="false">
        @if ($hotels->isEmpty())
            <x-empty-state icon="fa-hotel" text="No hotels yet. Add your hotel to see how AI assistants recommend it."
                           action-label="Add your hotel" :action-url="route('hotels.create')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Hotel</th>
                            <th scope="col">City</th>
                            <th scope="col" class="cv-num">Latest visibility</th>
                            <th scope="col">Last scan</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="cv-num">Active prompts</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hotels as $hotel)
                            @php($summary = $summaries[$hotel->id])
                            <tr>
                                <td class="fw-semibold">
                                    <a href="{{ $hotel->isSetUp() ? route('hotels.show', $hotel) : route('hotels.setup', $hotel) }}">{{ $hotel->name }}</a>
                                </td>
                                <td>{{ $hotel->city }}, {{ $hotel->country }}</td>
                                <td class="cv-num">{{ \App\Services\Dashboard\DashboardService::pct($summary['visibility']) }}</td>
                                <td>{{ $summary['last_scan_at']?->setTimezone($timezone)->format('j M Y') ?? '—' }}</td>
                                <td>
                                    @if (! $hotel->isSetUp())
                                        <x-badge-status label="Finish setup" variant="warning" icon="fa-pen" />
                                    @elseif ($summary['latest'])
                                        <x-badge-status :status="$summary['latest']->status" />
                                    @else
                                        <x-badge-status label="No scans yet" variant="neutral" />
                                    @endif
                                </td>
                                <td class="cv-num">{{ $hotel->prompts_count }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($hotel->isSetUp())
                                        <a href="{{ route('hotels.edit', $hotel) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    @else
                                        <a href="{{ route('hotels.setup', $hotel) }}" class="btn btn-sm btn-primary">
                                            Finish setup <span class="visually-hidden">for {{ $hotel->name }}</span>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts::app>
