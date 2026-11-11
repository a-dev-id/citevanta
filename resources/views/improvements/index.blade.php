@extends('layouts.app', ['title' => 'Action Plan'])
@section('actions')@if($workspace->canManage())
<form method="post" action="{{ route('improvements.refresh') }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="search" /> Generate / refresh from saved results</button></form>
@endif @endsection
@section('content')
<p class="text-secondary">Evidence-based recommendations for {{ $workspace->hotel()->name }}. No API request is needed. Unresolved monitored-name matches require manual analysis review before inclusion. Actions are hypotheses until verified by your team; saved answers do not prove website defects.</p>
<div class="row row-cards mb-4">@foreach(['open'=>'Open opportunities','high'=>'High priority','in_progress'=>'In progress','completed'=>'Completed'] as $key=>$label)<div class="col-6 col-lg-3"><div class="card card-body"><div class="subheader">{{ $label }}</div><div class="h1 my-2">{{ $summary[$key] }}</div></div></div>@endforeach</div>
<div class="card">
<div class="card-header"><h2 class="card-title">Recommendations</h2></div>
<div class="card-body border-bottom"><form method="get" action="{{ route('improvements.index') }}" class="row g-2 align-items-end"><div class="col-sm-6 col-lg-4"><label class="form-label" for="status-filter">Review status</label><select id="status-filter" name="status" class="form-select"><option value="">All statuses</option>@foreach(\App\Models\Improvement::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div><div class="col-auto"><button class="btn" type="submit">Filter</button></div></form></div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Recommendation</th><th>Priority</th><th>Confidence</th><th>Evidence</th><th>Status</th><th>Created</th></tr></thead><tbody>
@forelse($improvements as $item)<tr><td class="prompt-cell"><a href="{{ route('improvements.show',$item) }}">{{ $item->title }}</a><div class="text-secondary small mt-1">{{ $item->category }}</div><div class="small mt-1">{{ $item->evidence[0]['prompt']??'' }}</div>@unless($item->active)<div class="text-secondary small mt-1">Not observed in latest refresh; review history retained.</div>@endunless</td><td><span class="badge {{ $item->priority==='high'?'bg-red-lt':($item->priority==='medium'?'bg-yellow-lt':'bg-secondary-lt') }}">{{ ucfirst($item->priority) }}</span></td><td>{{ ucfirst($item->confidence) }}</td><td>{{ count($item->evidence) }} tests</td><td><x-status-badge :status="$item->status" /></td><td class="text-nowrap">{{ $item->created_at->format('d M Y') }}</td></tr>
@empty<tr><td colspan="6" class="p-4 text-secondary">No recommendations yet. Generate them from valid completed saved tests using the button above.</td></tr>@endforelse
</tbody></table></div>
@if($improvements->hasPages())<div class="card-footer">{{ $improvements->links() }}</div>@endif
</div>
<p class="text-secondary small mt-3">Priority reflects repeated observations, not estimated revenue or proven impact. Confidence concerns the investigation hypothesis. Refresh after correcting hotel or citation analysis; review status, notes, and past AI explanations are retained.</p>
@endsection
