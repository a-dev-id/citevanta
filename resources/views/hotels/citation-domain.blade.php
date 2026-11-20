<x-layouts::app :title="$domain" :breadcrumbs="['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Citations' => route('hotels.citations', $hotel), $domain => null]">
    <x-card title="Links cited from {{ $domain }}" subtitle="Last {{ \App\Services\Dashboard\CitationReport::SCANS }} completed scans" :flush="true" :collapsible="false">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Link</th>
                        <th scope="col">Cited for these prompts</th>
                        <th scope="col">Scans</th>
                        <th scope="col" class="cv-num">Citations</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($urls as $row)
                        <tr>
                            <td style="max-width: 360px">
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="text-break">
                                    {{ $row['title'] ?: $row['url'] }}
                                    <i class="fa-solid fa-arrow-up-right-from-square fa-xs" aria-hidden="true"></i>
                                    <span class="visually-hidden">(opens in a new tab)</span>
                                </a>
                                @if ($row['title'])
                                    <span class="d-block small text-muted text-break">{{ $row['url'] }}</span>
                                @endif
                            </td>
                            <td>
                                <ul class="small mb-0 ps-3">
                                    @foreach ($row['prompts'] as $prompt)
                                        <li>{{ $prompt }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="text-nowrap">
                                @foreach ($row['scans'] as $scanId)
                                    <a href="{{ route('hotels.scans.show', [$hotel, $scanId]) }}">#{{ $scanId }}</a>
                                @endforeach
                            </td>
                            <td class="cv-num">{{ $row['citations'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <a href="{{ route('hotels.citations', $hotel) }}"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> All cited domains</a>
        </x-slot:footer>
    </x-card>
</x-layouts::app>
