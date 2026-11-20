@php($links = $d->links)

<x-card title="Where AI gets its information" subtitle="Cited links by type ({{ $links['total'] }} citations)" :flush="true">
    @if ($links['total'] === 0)
        <x-empty-state icon="fa-link" text="No links were cited in this scan." />
    @else
        <div class="px-3 pt-3">
            <div class="progress" style="height: 10px" role="img"
                 aria-label="{{ collect($links['types'])->filter(fn ($row) => $row['citations'] > 0)->map(fn ($row) => $row['type']->label().' '.$row['share'].'%')->implode(', ') }}">
                @foreach ($links['types'] as $row)
                    @if ($row['citations'] > 0)
                        <div class="progress-bar {{ $row['type']->cssClass() }}" style="width: {{ $row['share'] }}%"></div>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th scope="col">Type <x-domain-type.help /></th>
                        <th scope="col" class="cv-num">Citations</th>
                        <th scope="col" class="cv-num">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($links['types'] as $row)
                        <tr @class(['text-muted' => $row['citations'] === 0])>
                            <td>
                                <span class="cv-domain-dot {{ $row['type']->cssClass() }}" aria-hidden="true"></span>{{ $row['type']->label() }}
                                @if ($row['type'] === \App\Enums\DomainType::Direct)
                                    <span class="d-block small text-muted" style="padding-left: 18px">{{ $d->ownDomain ?? 'No website set' }}</span>
                                @endif
                            </td>
                            <td class="cv-num">{{ $row['citations'] }}</td>
                            <td class="cv-num">{{ \App\Services\Dashboard\DashboardService::pct($row['share']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>
