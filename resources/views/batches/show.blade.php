@extends('layouts.app',['title'=>'Campaign #'.$batch->id])
@section('actions')
<a class="btn" href="{{ route('batches.index') }}">Back to campaigns</a>
<a class="btn" href="{{ route('organizations.index',['section'=>'usage']) }}">Plan &amp; Usage</a>
@endsection
@section('content')
@include('organizations.credits')
@php
    $ledger = app(\App\Services\CreditLedger::class);
    $required = $ledger->requiredForBatch($batch);
    $canStart = $simulation
        ? $required <= $ledger->balance()['remaining']
        : config('spending.execution_enabled') && $batch->estimated_micros !== null && $balance['remaining'] !== null && $balance['settings']->monthly_limit_micros > 0;
@endphp
<p class="text-secondary">Required credits: <strong>{{ $batch->request_count * $ledger->weight($batch->provider->identifier,$batch->model) }}</strong> for the planned searches. Completed searches use credits; stopping a campaign releases unused credits.</p>
<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">{{ $batch->name }}</h2><span class="ms-auto"><x-status-badge :status="$batch->status" /></span></div>
    <div class="card-body">
        @if($batch->status_note)
            <div class="alert alert-warning">{{ \App\Services\MonitoringMessages::publicText($batch->status_note) }}</div>
        @endif
        <div class="row g-3">
            @foreach(['Planned searches'=>$batch->request_count,'Completed'=>$batch->items->where('status','completed')->count(),'Failed / uncertain'=>$batch->items->whereIn('status',['failed','interrupted'])->count(),'Remaining'=>$batch->items->whereIn('status',['pending','retry_wait','processing'])->count(),'Cancelled'=>$batch->items->where('status','cancelled')->count(),'Search attempts'=>$attempts->count().' / '.$batch->request_limit] as $label=>$value)
                <div class="col-sm-6 col-xl-3"><div class="subheader">{{ $label }}</div><div class="mt-1">{{ $value }}</div></div>
            @endforeach
        </div>
        <p class="text-secondary small mt-3">Started: {{ $batch->started_at?->format('d M Y H:i') ?? 'Not started' }} UTC.</p>
        @if($workspace->canManage())
            @if(in_array($batch->status,['draft','paused'],true))
                <form method="post" action="{{ route('batches.start',$batch) }}" data-campaign-confirmations data-can-start="{{ $canStart ? '1':'0' }}">
                    @csrf
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_credits" value="1" required><span class="form-check-label">I reviewed the prompts, market and required credits.</span></label>
                    @if($batch->request_count>=config('spending.large_batch_warning'))
                        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="large_warning" value="1" required><span class="form-check-label">I confirm starting all {{ $batch->request_count }} planned searches.</span></label>
                    @endif
                    <button class="btn btn-primary mt-2" type="submit" @disabled(!$canStart)>{{ $batch->status==='draft' ? 'Start Campaign':'Resume Campaign' }}</button>
                    @unless($canStart)
                        <p class="text-secondary small mt-2">{{ $simulation ? 'Not enough available credits to start this campaign.' : 'Monitoring is currently unavailable.' }}</p>
                    @endunless
                </form>
            @endif
            @if($simulation && $batch->status==='running')
                <form class="mt-3" method="post" action="{{ route('batches.process',$batch) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Process Next Searches</button>
                    <p class="text-secondary small mt-2">Process up to three simulated searches and update campaign progress.</p>
                </form>
            @endif
            @if(in_array($batch->status,['draft','running','paused','cancelling'],true))
                <form class="mt-3" method="post" action="{{ route('batches.cancel',$batch) }}">
                    @csrf
                    <button class="btn btn-outline-danger" type="submit">Stop Campaign</button>
                </form>
            @endif
        @endif
    </div>
</div>
<div class="card mb-4">
    <div class="card-header"><h2 class="card-title">Planned searches and results</h2><a class="btn btn-sm ms-auto" href="{{ route('batches.show',$batch) }}">Refresh status</a></div>
    <div class="table-responsive"><table class="table card-table"><thead><tr><th>Prompt</th><th>Market</th><th>Repetition</th><th>Status</th><th>Search attempts</th><th>Result</th></tr></thead><tbody>
        @foreach($batch->items as $item)
            <tr><td class="prompt-cell">{{ $item->prompt_snapshot['text'] }}</td><td>{{ $item->market_snapshot['name'] }}</td><td>{{ $item->repetition }}</td><td><x-status-badge :status="$item->status" />@if($item->status_note)<div class="small text-secondary text-wrap prompt-cell mt-1">{{ \App\Services\MonitoringMessages::publicText($item->status_note) }}</div>@endif</td><td>{{ $item->attempts }}</td><td>@if($item->test_run_id)<a class="btn btn-sm" href="{{ route('tests.show',$item->test_run_id) }}">View response</a>@else<span class="text-secondary">Not processed</span>@endif</td></tr>
        @endforeach
    </tbody></table></div>
</div>
@if($attempts->isNotEmpty())
    <div class="card"><div class="card-header"><h2 class="card-title">Saved searches</h2></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Search</th><th>Status</th></tr></thead><tbody>
        @foreach($attempts as $run)
            <tr><td><a href="{{ route('tests.show',$run) }}">Search #{{ $run->id }}</a></td><td>{{ $run->status }}</td></tr>
        @endforeach
    </tbody></table></div></div>
@endif
@endsection
