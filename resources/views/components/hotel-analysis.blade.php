@props(['run'])
<div class="card mb-4" data-brand-analysis>
    <div class="card-header">
        <h2 class="card-title">Brand visibility analysis</h2>
        @if($workspace->canManage())
            <a class="btn btn-sm ms-auto" href="{{ route('visibility.review',$run) }}">Review / correct</a>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table card-table">
            <thead><tr><th>Brand</th><th>Finding</th><th>Comparative rank</th><th>Evidence and citations</th></tr></thead>
            <tbody>
                @forelse($run->response->assessments->where('mentioned',true) as $assessment)
                    <tr data-brand-finding="{{ $assessment->hotel_id }}">
                        <td class="prompt-cell">{{ $assessment->hotel->name }}<div class="text-secondary small">{{ $assessment->hotel->isMonitoredBrand() ? 'Monitored brand' : 'Competitor' }}</div></td>
                        <td>{{ $assessment->recommended ? 'Recommended' : 'Mention only' }}@if($assessment->manual)<span class="badge bg-blue-lt ms-1">Reviewed</span>@endif</td>
                        <td data-comparative-rank>{{ $assessment->rank ?? 'Not ranked' }}</td>
                        <td class="prompt-cell">
                            <details>
                                <summary>View response evidence</summary>
                                <blockquote class="text-secondary text-break mt-3 mb-2">{{ $assessment->evidence }}</blockquote>
                                <a href="#response-evidence">Read the source response</a>
                                <ul class="list-unstyled mt-2 mb-0">
                                    @forelse($run->response->citations->whereIn('id',$assessment->supporting_citation_ids) as $source)
                                        <li><a href="#citation-source-{{ $source->id }}">{{ $source->title ?: $source->domain }}</a> <span class="text-secondary small">{{ \App\Services\CitationAnalysis::label($source->classification) }}</span></li>
                                    @empty
                                        <li class="text-secondary small">No citations linked to this finding.</li>
                                    @endforelse
                                </ul>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-secondary">No verified brand names detected.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer text-secondary small">Comparative ranks require an explicitly ranked comparison of multiple brands. Numbering alone is insufficient. A mention, a recommendation and a citation are separate signals; a citation does not establish an endorsement. Unresolved identities: {{ count($run->response->unresolved_hotels ?? []) }}.</div>
</div>
