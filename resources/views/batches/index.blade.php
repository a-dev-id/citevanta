@extends('layouts.app', ['title'=>'Monitoring Campaigns'])
@section('actions')
@if($canCreate)<a class="btn btn-primary" href="{{ route('batches.create') }}"><x-icon name="plus" /> New Campaign</a>@endif
@endsection
@section('content')
@include('organizations.credits')
<p class="text-secondary">Run a set of search prompts, follow progress, and review the saved results. Creating a preview does not run any searches.</p>
<div class="card mb-4">
    <div class="card-header"><ul class="nav nav-tabs card-header-tabs">
        @foreach(['all'=>'All campaigns', 'active'=>'In progress', 'completed'=>'Completed history', 'templates'=>'Pilot templates'] as $key=>$label)
            <li class="nav-item"><a class="nav-link {{ $filter===$key?'active':'' }}" href="{{ route('batches.index',['view'=>$key]) }}" @if($filter===$key) aria-current="page" @endif>{{ $label }}@if(isset($counts[$key]))<span class="badge bg-secondary-lt ms-2">{{ $counts[$key] }}</span>@endif</a></li>
        @endforeach
    </ul></div>
    @if($filter==='templates')
        <div class="card-body">
            @if($pilotAvailable)
                <h2 class="h3">Nandini visibility pilot</h2>
                <p class="text-secondary">12 unbranded travel questions, one Australia search context, and one repetition. Review the preview and credit requirements before approving any searches.</p>
                <a class="btn" href="{{ route('pilot.index') }}">{{ $pilotBatch?'Open existing pilot':'Preview pilot template' }}</a>
            @else
                <div class="empty"><p class="empty-title">No pilot templates available</p><p class="empty-subtitle text-secondary">Templates depend on the selected workspace and plan. You can use your active search prompts to configure a campaign.</p>@if($canCreate)<div class="empty-action"><a class="btn btn-primary" href="{{ route('batches.create') }}">New Campaign</a></div>@endif</div>
            @endif
        </div>
    @else
        <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Campaign</th><th>Started (UTC)</th><th>Progress</th><th>Failed / uncertain</th><th>Remaining</th><th>Credits used</th><th>Required credits</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse($batches as $batch)
            <tr><td>{{ $batch->name }}@if($batch->pilot_key)<div class="text-secondary small">Pilot</div>@endif</td><td>{{ $batch->started_at?->format('d M Y H:i')??'Not started' }}</td>
                <td><div class="text-nowrap mb-2">{{ $batch->completed_count }} / {{ $batch->request_count }} completed</div><div class="progress progress-sm" role="progressbar" aria-label="Completed searches for {{ $batch->name }}" aria-valuenow="{{ $batch->completed_count }}" aria-valuemin="0" aria-valuemax="{{ max(1,$batch->request_count) }}"><div class="progress-bar bg-primary" style="width: {{ min(100,round($batch->completed_count/max(1,$batch->request_count)*100)) }}%"></div></div></td>
                <td>{{ $batch->failed_count }}</td><td>{{ $batch->remaining_count }}</td><td>{{ number_format((int)$batch->credits_used) }}</td><td>{{ $batch->request_count * app(\App\Services\CreditLedger::class)->weight($batch->provider->identifier,$batch->model) }}</td><td><x-status-badge :status="$batch->status" /></td><td><a class="btn btn-sm" href="{{ $batch->pilot_key && $pilotAvailable ? route('pilot.index') : route('batches.show',$batch) }}">View campaign</a></td></tr>
        @empty
            <tr><td colspan="9"><div class="empty"><p class="empty-title">No campaigns in this view</p><p class="empty-subtitle text-secondary">Your campaign previews and saved results will appear here.</p>@if($canCreate)<div class="empty-action"><a class="btn btn-primary" href="{{ route('batches.create') }}">New Campaign</a></div>@endif</div></td></tr>
        @endforelse
        </tbody></table></div><div class="card-footer">{{ $batches->links() }}</div>
    @endif
</div>
@if($filter!=='templates' && $pilotAvailable)<p><a href="{{ route('batches.index',['view'=>'templates']) }}">Explore pilot templates</a></p>@endif
<p class="text-secondary small">Searches begin only after approval and are processed in small groups. No recurring monitoring is enabled. Service availability and plan allowances apply to every request.</p>
@endsection
