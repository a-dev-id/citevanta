@extends('layouts.app', ['title'=>'Test result #'.$run->id])

@section('actions')<a href="{{ route('tests.index') }}" class="btn"><x-icon name="arrow-left" /> Back to history</a>
@endsection

@section('content')<div class="row row-cards">
<div class="col-lg-8">
<div class="card mb-4">
<div class="card-header">
<h2 class="card-title">{{ $run->is_simulated ? 'Simulated search response' : 'Search response' }}</h2>
<span class="ms-auto">
<x-status-badge :status="$run->status" />
</span>
</div>
<div class="card-body">@if($run->response?->error_details)<div class="alert alert-warning">{{ \App\Services\MonitoringMessages::publicText($run->response->error_details) }}</div>@endif @if($run->response?->text)<div class="answer" id="response-evidence">{{ app(\App\Services\CitationRenderer::class)->render($run->response) }}</div>@else<p class="text-secondary mb-0">No answer is available for this test.</p>@endif</div>
</div>
@if($analyzed)<x-hotel-analysis :run="$run" />@else<div class="card mb-4"><div class="card-body text-secondary">Visibility analysis is available for valid completed web-search tests.</div></div>@endif
@foreach([true=>'Official website citations',false=>'Other source citations'] as $official=>$label)<div class="card mb-4">
<div class="card-header">
<h2 class="card-title">{{ $label }}</h2>
</div>
<div class="list-group list-group-flush">@forelse(($run->response?->citations ?? collect())->where('is_official',(bool)$official) as $citation)<a class="list-group-item text-break" id="citation-source-{{ $citation->id }}" href="{{ $citation->url }}" target="_blank" rel="noopener noreferrer">
<div>{{ $citation->title ?: $citation->domain }} <x-icon name="external-link" class="icon-inline" /></div>
<div class="text-secondary small">{{ $citation->url }}</div>@if($citation->classification)<span class="badge bg-secondary-lt mt-2">{{ \App\Services\CitationAnalysis::label($citation->classification) }}</span>@endif
</a>@empty<div class="card-body text-secondary">No citations recorded in this category.</div>@endforelse</div>
</div>@endforeach</div>
<div class="col-lg-4">
<div class="card mb-4">
<div class="card-header">
<h2 class="card-title">Test details</h2>
</div>
<div class="card-body">
@php
    $mentioned = $analyzed
        ? $run->response->assessments->contains(fn($assessment) => $assessment->hotel->isMonitoredBrand() && $assessment->mentioned)
        : $run->response?->mentions->contains('is_nandini',true);
    $recommended = $analyzed
        ? $run->response->assessments->contains(fn($assessment) => $assessment->hotel->isMonitoredBrand() && $assessment->recommended)
        : $run->response?->mentions->where('is_nandini',true)->contains('is_recommendation',true);
    $details = [
        'Provider'=>$run->provider->name,
        'Model'=>$run->model,
        'Executed'=>$run->executed_at->format('d M Y, H:i:s').' UTC',
        'Market'=>$run->market_snapshot['name'],
        'Prompt version'=>$run->prompt_version,
        'Repetition'=>$run->repetition,
        $workspace->shortName().' mentioned'=>$mentioned ? 'Yes':'No',
        $workspace->shortName().' recommendation signal'=>$recommended ? 'Yes':'No',
        'Official website cited'=>$run->response?->citations->contains('is_official',true) ? 'Yes':'No',
    ];
@endphp
<dl class="mb-0" data-test-details>
    @foreach($details as $label=>$value)
        <dt class="small text-secondary fw-normal">{{ $label }}</dt>
        <dd class="mb-3">{{ $value }}</dd>
    @endforeach
</dl>
</div>
</div>
<div class="card">
<div class="card-body">
<h2 class="card-title">Exact prompt</h2>
<p class="mb-0">{{ $run->prompt_text }}</p>
</div>
</div>
</div>
</div>
@endsection
