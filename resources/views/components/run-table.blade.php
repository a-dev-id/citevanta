@props(['runs'])
@if($runs->isEmpty())
<div class="empty"><p class="empty-title">No tests yet</p><p class="empty-subtitle text-secondary">Saved AI search answers, brand mentions, and sources will appear here.</p>@if($workspace->canManage())<div class="empty-action"><a href="{{ route('tests.create') }}" class="btn btn-primary">Create your first test</a></div>@endif</div>
@else
<div class="table-responsive">
<table class="table table-vcenter card-table">
<thead>
<tr>
<th>Date</th>
<th>Provider / model</th>
<th>Search prompt</th>
<th>Market</th>
<th>{{ $workspace->shortName() }} mentioned</th>
<th>Status</th>
<th>
</th>
</tr>
</thead>
<tbody>@foreach($runs as $run)<tr>
<td class="text-nowrap">{{ $run->executed_at->format('d M Y') }}<div class="small text-secondary">{{ $run->executed_at->format('H:i') }} UTC</div>
</td>
<td>{{ $run->provider->name }}<div class="small text-secondary">{{ $run->model }}</div>
</td>
<td class="prompt-cell">{{ $run->prompt_text }}<div class="small text-secondary">Version {{ $run->prompt_version }}</div>
</td>
<td>{{ $run->market_snapshot['name'] }}</td>
<td>{{ $run->status==='completed' && $run->response?->analyzed_at ? ($run->response->assessments->contains(fn($a) => $a->hotel->isMonitoredBrand() && $a->mentioned) ? 'Yes' : 'No') : '—' }}</td>
<td>
<x-status-badge :status="$run->status" />
@if($run->response?->error_details)
<div class="small text-secondary text-wrap mt-1 prompt-cell">{{ \App\Services\MonitoringMessages::publicText($run->response->error_details) }}</div>
@endif
</td>
<td>
<a href="{{ route('tests.show',$run) }}" class="btn btn-sm">View result</a>
</td>
</tr>@endforeach</tbody>
</table>
</div>
@endif
