<x-card title="Top cited domains" :flush="true">
    <x-slot:actions>
        @if ($d->ownDomain === null)
            <x-badge-status label="Add your website to check" variant="neutral" icon="fa-globe" class="me-1" />
        @elseif ($d->ownCited)
            <x-badge-status label="Your website cited" variant="success" icon="fa-circle-check" class="me-1" />
        @else
            <x-badge-status label="Your website not cited" variant="warning" icon="fa-triangle-exclamation" class="me-1" />
        @endif
    </x-slot:actions>

    @if ($d->domains === [])
        <x-empty-state icon="fa-link" text="No links were cited in this scan." />
    @else
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th scope="col">Domain</th><th scope="col">Type <x-domain-type.help /></th><th scope="col" class="cv-num">Citations</th></tr>
                </thead>
                <tbody>
                    @foreach ($d->domains as $row)
                        <tr @class(['is-highlighted' => $row['type'] === 'own'])>
                            <td>{{ $row['domain'] }} @if ($row['type'] === 'own')<span class="text-muted fw-normal">(your website)</span>@endif</td>
                            <td><x-domain-type.badge :type="$row['type']" /></td>
                            <td class="cv-num">{{ $row['citations'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-slot:footer>
        <a href="{{ route('hotels.citations', $d->hotel) }}">All citations</a>
    </x-slot:footer>
</x-card>
