@php($tz = $hotel->team?->timezoneName() ?? config('citevanta.default_timezone'))

<x-layouts::app title="Scan history" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Scan history' => null]">
    <x-card title="Scans of {{ $hotel->name }}" :flush="true" :collapsible="false">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end px-3 py-3 border-bottom">
            <div>
                <label for="filter-status" class="form-label small mb-1">Status</label>
                <select id="filter-status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach (\App\Enums\ScanStatus::cases() as $status)
                        @continue($status === \App\Enums\ScanStatus::Pending)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-trigger" class="form-label small mb-1">Trigger</label>
                <select id="filter-trigger" name="trigger" class="form-select form-select-sm">
                    <option value="">All triggers</option>
                    @foreach (\App\Enums\ScanTrigger::cases() as $trigger)
                        <option value="{{ $trigger->value }}" @selected(($filters['trigger'] ?? '') === $trigger->value)>{{ $trigger->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('hotels.scans', $hotel) }}" class="btn btn-sm btn-link">Clear</a>
            @endif
        </form>

        @if ($scans->isEmpty())
            <x-empty-state icon="fa-clock-rotate-left" text="No scans match these filters." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Scan</th>
                            <th scope="col">Trigger</th>
                            <th scope="col">Status</th>
                            <th scope="col">Date</th>
                            <th scope="col" class="cv-num">Answers</th>
                            <th scope="col" class="cv-num">Failed</th>
                            <th scope="col" class="cv-num">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scans as $run)
                            <tr>
                                <td>
                                    @if ($run->status->isInProgress())
                                        Scan #{{ $run->id }}
                                    @else
                                        <a href="{{ route('hotels.scans.show', [$hotel, $run]) }}">Scan #{{ $run->id }}</a>
                                    @endif
                                </td>
                                <td>{{ $run->trigger?->label() ?? '—' }}</td>
                                <td><x-badge-status :status="$run->status" /></td>
                                <td>{{ ($run->finished_at ?? $run->created_at)->setTimezone($tz)->format('j M Y, H:i') }}</td>
                                <td class="cv-num">{{ $run->answers_count }}</td>
                                <td class="cv-num">{{ $run->failed_count }}</td>
                                <td class="cv-num">${{ number_format((float) $run->cost_usd, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($scans->hasPages())
            <x-slot:footer>{{ $scans->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
