<x-card title="Competitor share of voice" subtitle="All engines" :flush="true">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Hotel</th>
                    <th scope="col" class="cv-num">Mentions</th>
                    <th scope="col" class="cv-num">Visibility</th>
                    <th scope="col" class="cv-num">Share of voice</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($d->competitors as $row)
                    <tr @class(['is-highlighted' => $row['is_self']])>
                        <td>{{ $row['name'] }} @if ($row['is_self'])<span class="text-muted fw-normal">(you)</span>@endif</td>
                        <td class="cv-num">{{ $row['mentions'] }}</td>
                        <td class="cv-num">{{ \App\Services\Dashboard\DashboardService::pct($row['visibility']) }}</td>
                        <td class="cv-num">{{ \App\Services\Dashboard\DashboardService::pct($row['share']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted">No competitors were tracked in this scan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
