@php($tz = $hotel->team?->timezoneName() ?? config('citevanta.default_timezone'))

<x-layouts::app title="Citations" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Citations' => null]">
    <x-card title="Cited domains" subtitle="Sites AI assistants cited in the last {{ \App\Services\Dashboard\CitationReport::SCANS }} completed scans of {{ $hotel->name }}" :flush="true" :collapsible="false">
        @if ($domains === [])
            <x-empty-state icon="fa-link" text="No citations yet. They appear after your first completed scan." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Domain</th>
                            <th scope="col">Type <x-domain-type.help /></th>
                            @foreach ($scans as $scan)
                                <th scope="col" class="cv-num">
                                    <a href="{{ route('hotels.scans.show', [$hotel, $scan]) }}">#{{ $scan->id }}</a>
                                    <span class="d-block small fw-normal text-muted">{{ $scan->finished_at->setTimezone($tz)->format('j M') }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="cv-num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($domains as $row)
                            <tr @class(['is-highlighted' => $row['type'] === 'own'])>
                                <td>
                                    <a href="{{ route('hotels.citations.domain', [$hotel, $row['domain']]) }}">{{ $row['domain'] }}</a>
                                    @if ($row['type'] === 'own')<span class="text-muted fw-normal">(your website)</span>@endif
                                </td>
                                <td><x-domain-type.badge :type="$row['type']" /></td>
                                @foreach ($scans as $scan)
                                    <td class="cv-num">{{ $row['per_scan'][$scan->id] ?: '—' }}</td>
                                @endforeach
                                <td class="cv-num fw-semibold">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts::app>
