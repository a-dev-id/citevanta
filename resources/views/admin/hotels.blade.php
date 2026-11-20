<x-layouts::app title="Hotels" :breadcrumbs="['Admin' => null, 'Hotels' => null]">
    <x-card title="All hotels" :flush="true" :collapsible="false">
        @if ($hotels->isEmpty())
            <x-empty-state icon="fa-building" text="No hotels yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Hotel</th><th scope="col">Location</th><th scope="col">Team</th>
                            <th scope="col">Setup</th><th scope="col" class="cv-num">Scans</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hotels as $hotel)
                            <tr>
                                <td class="fw-semibold">{{ $hotel->name }}</td>
                                <td>{{ $hotel->city }}, {{ $hotel->country }}</td>
                                <td>{{ $hotel->team?->name ?? 'No team (CLI)' }}</td>
                                <td>
                                    @if ($hotel->isSetUp())
                                        <x-badge-status label="Ready" variant="success" />
                                    @else
                                        <x-badge-status label="Step {{ $hotel->setup_step }}" variant="warning" />
                                    @endif
                                </td>
                                <td class="cv-num">{{ $hotel->scan_runs_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($hotels->hasPages())
            <x-slot:footer>{{ $hotels->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
