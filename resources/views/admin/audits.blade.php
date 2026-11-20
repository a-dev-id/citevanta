<x-layouts::app title="Audits" :breadcrumbs="['Admin' => null, 'Audits' => null]">
    <div class="row gx-3">
        <div class="col-md-4">
            <x-card title="Today" :collapsible="false">
                <p class="h4 mb-1">{{ $today }} <span class="text-muted fs-6">of {{ $budget }} new audits</span></p>
                <p class="small text-muted mb-0">Cost today ${{ number_format($costs['today'], 4) }}. Repeat requests reuse a recent report and cost nothing.</p>
            </x-card>
        </div>
        <div class="col-md-4">
            <x-card title="This month" :collapsible="false">
                <p class="h4 mb-1">${{ number_format($costs['month'], 4) }}</p>
                <p class="small text-muted mb-0">Total cost of free audits since the 1st.</p>
            </x-card>
        </div>
        <div class="col-md-4">
            <x-card title="Cost per day" :flush="true" :collapsible="false">
                @if ($costs['days'] === [])
                    <x-empty-state icon="fa-coins" text="No audits in the last 30 days." />
                @else
                    <div class="table-responsive" style="max-height: 160px">
                        <table class="table table-sm mb-0">
                            <tbody>
                                @foreach ($costs['days'] as $day => $cost)
                                    <tr><td>{{ \Illuminate\Support\Carbon::parse($day)->format('j M Y') }}</td><td class="cv-num">${{ number_format($cost, 4) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <x-card title="Leads" subtitle="Free AI visibility audits from the website" :flush="true" :collapsible="false">
        <x-slot:actions>
            <a href="{{ route('admin.audits.export') }}" class="btn btn-sm btn-outline-primary me-1">
                <i class="fa-solid fa-file-csv me-1" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>

        @if ($audits->isEmpty())
            <x-empty-state icon="fa-magnifying-glass" text="No audits yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Hotel</th>
                            <th scope="col">Email</th>
                            <th scope="col">Consent</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="cv-num">Score</th>
                            <th scope="col">Date</th>
                            <th scope="col">Trial</th>
                            <th scope="col" class="cv-num">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($audits as $audit)
                            @php($results = $audit->results())
                            <tr>
                                <td>
                                    <a href="{{ route('audit.show', $audit->token) }}" target="_blank" rel="noopener">{{ $audit->hotel_name }}</a>
                                    <span class="d-block small text-muted">{{ $audit->city }}, {{ $audit->country }}@if ($audit->source_audit_id) · reused report @endif</span>
                                </td>
                                <td>{{ $audit->email }}</td>
                                <td>{{ $audit->consent ? 'Yes' : 'No' }}</td>
                                <td><x-badge-status :status="$results->status" /></td>
                                <td class="cv-num">{{ $results->visibility !== null ? round($results->visibility).'%' : '—' }}</td>
                                <td>{{ $audit->created_at->format('j M Y, H:i') }}</td>
                                <td>
                                    @if ($audit->converted_team_id)
                                        <x-badge-status label="Yes" variant="success" /> <span class="small text-muted">{{ $audit->convertedTeam?->name }}</span>
                                    @else
                                        No
                                    @endif
                                </td>
                                <td class="cv-num">{{ $audit->source_audit_id ? '—' : '$'.number_format((float) $audit->cost_usd, 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($audits->hasPages())
            <x-slot:footer>{{ $audits->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
