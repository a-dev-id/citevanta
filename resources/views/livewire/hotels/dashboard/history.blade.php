<x-card title="Scan history" :flush="true">
    @if ($d->history->isEmpty())
        <x-empty-state icon="fa-clock-rotate-left" text="No scans yet." />
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
                        <th scope="col" class="cv-num">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d->history as $run)
                        <tr @class(['is-highlighted' => $d->scan?->is($run)])>
                            <td>
                                @if (in_array($run->status->value, \App\Enums\ScanStatus::finishedWithResults(), true) || $run->status === \App\Enums\ScanStatus::Failed)
                                    <a href="{{ route('hotels.scans.show', [$d->hotel, $run]) }}">Scan #{{ $run->id }}</a>
                                @else
                                    Scan #{{ $run->id }}
                                @endif
                            </td>
                            <td>{{ $run->trigger?->label() ?? '—' }}</td>
                            <td><x-badge-status :status="$run->status" /></td>
                            <td>{{ $fmt($run->finished_at ?? $run->created_at) }}</td>
                            <td class="cv-num">{{ $run->answers_count ?: ($run->status->isInProgress() ? '…' : 0) }}</td>
                            <td class="cv-num">${{ number_format((float) $run->cost_usd, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <x-slot:footer>
        <a href="{{ route('hotels.scans', $d->hotel) }}">All scans</a>
    </x-slot:footer>
</x-card>
