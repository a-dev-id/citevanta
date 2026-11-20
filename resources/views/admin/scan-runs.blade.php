<x-layouts::app title="Scan runs" :breadcrumbs="['Admin' => null, 'Scan runs' => null]">
    <x-flash />

    <x-card title="All scan runs" :flush="true" :collapsible="false">
        @if ($scanRuns->isEmpty())
            <x-empty-state icon="fa-list-check" text="No scans have run yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Scan</th>
                            <th scope="col">Hotel</th>
                            <th scope="col">Trigger</th>
                            <th scope="col">Status</th>
                            <th scope="col">Started</th>
                            <th scope="col" class="cv-num">Answers</th>
                            <th scope="col" class="cv-num">Failed</th>
                            <th scope="col" class="cv-num">Cost</th>
                            <th scope="col" class="cv-num">Duration</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scanRuns as $run)
                            @php
                                $planned = $run->plannedCalls();
                                $duration = $run->started_at && $run->finished_at ? $run->started_at->diff($run->finished_at) : null;
                            @endphp
                            <tr>
                                <td>#{{ $run->id }}</td>
                                <td>
                                    {{ $run->hotel?->name }}
                                    <span class="d-block small text-muted">{{ $run->hotel?->team?->name ?? 'No team (CLI)' }}</span>
                                </td>
                                <td>{{ $run->trigger?->label() ?? '—' }}</td>
                                <td><x-badge-status :status="$run->status" /></td>
                                <td>{{ $run->started_at?->format('j M Y, H:i') ?? '—' }}</td>
                                <td class="cv-num">{{ $run->responses_count }}{{ $planned ? ' / '.$planned : '' }}</td>
                                <td class="cv-num">{{ $run->failed_count }}</td>
                                <td class="cv-num">
                                    ${{ number_format((float) $run->cost_usd, 4) }}
                                    @if ($run->cost_breakdown)
                                        <dl class="cv-cost-breakdown">
                                            @foreach (\App\Enums\AiPurpose::cases() as $purpose)
                                                @if ((float) ($run->cost_breakdown[$purpose->value] ?? 0) > 0)
                                                    <div><dt>{{ $purpose->label() }}</dt><dd>${{ number_format((float) $run->cost_breakdown[$purpose->value], 4) }}</dd></div>
                                                @endif
                                            @endforeach
                                        </dl>
                                    @endif
                                </td>
                                <td class="cv-num">{{ $duration ? $duration->format('%h:%I:%S') : '—' }}</td>
                                <td class="text-end">
                                    @if (($missing[$run->id] ?? 0) > 0)
                                        <form method="POST" action="{{ route('admin.scan-runs.retry', $run) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">
                                                <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i> Retry failed calls ({{ $missing[$run->id] }})
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                            @if (! empty($run->errors))
                                <tr>
                                    <td colspan="10" class="small text-muted pt-0">
                                        <details>
                                            <summary>{{ count($run->errors) }} error(s)</summary>
                                            <ul class="mb-0">
                                                @foreach ($run->errors as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($scanRuns->hasPages())
            <x-slot:footer>{{ $scanRuns->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
