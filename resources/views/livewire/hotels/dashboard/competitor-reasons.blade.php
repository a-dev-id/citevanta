@php($summary = $d->competitorReasons['summary'])

<x-card title="Why competitors win" subtitle="What AI assistants said about the competitors they recommended instead of you" :collapsible="false">
    @if (! $d->competitorReasons['enabled'])
        <x-empty-state icon="fa-power-off" text="Competitor reasons are switched off." />
    @elseif (! $summary || $summary['prompts'] === 0)
        <x-empty-state icon="fa-chess-knight" text="No competitor beat you in this scan, or reasons are not ready yet." />
    @else
        <div class="row gx-3">
            <div class="col-lg-6">
                <h4 class="cv-why__title">Top reasons competitors win</h4>
                <ul class="cv-why__reasons">
                    @foreach ($summary['reasons'] as $row)
                        <li>
                            <span class="fw-semibold">{{ $row['reason'] }}</span>
                            <x-badge-status :label="$row['hotel_has']->label()" :variant="$row['hotel_has']->variant()" />
                            <span class="d-block text-muted">
                                {{ implode(', ', $row['competitors']) }} · {{ $row['prompts'] }} {{ Str::plural('prompt', $row['prompts']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="col-lg-6">
                <h4 class="cv-why__title">Sources that mention competitors but not you</h4>
                @if ($summary['sources'] === [])
                    <p class="small text-muted mb-0">None in this scan.</p>
                @else
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th scope="col">Source</th><th scope="col">Recommends</th><th scope="col" class="cv-num">Prompts</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($summary['sources'] as $source)
                                <tr>
                                    <td>{{ $source['domain'] }}</td>
                                    <td>{{ implode(', ', $source['competitors']) }}</td>
                                    <td class="cv-num">{{ $source['prompts'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif
</x-card>
