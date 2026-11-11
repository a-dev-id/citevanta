@extends('layouts.app', ['title'=>'Quick Test'])

@section('content')
@include('organizations.credits')
<p class="text-secondary">Required credits: <strong>{{ app(\App\Services\CreditLedger::class)->weight(app(\App\Services\SimulatedMonitoring::class)->provider(),$monitoringModel) }}</strong> per standard response.</p>
@if(app(\App\Services\PlanCatalog::class)->current()['key']==='free' && !auth()->user()->hasVerifiedEmail())<div class="alert alert-warning"><a href="{{ route('verification.notice') }}">Verify your email</a> before using Free search credits.</div>@endif<p class="text-secondary mb-4">Run one controlled web search and save the complete result for review.</p>@unless($configured)<div class="alert alert-warning">Monitoring is currently unavailable. Please contact support.</div>@endunless<div class="card">
<div class="card-header">
<h2 class="card-title">New search test</h2>
<span class="badge bg-green-lt ms-auto">Manual execution</span>
</div>
<form method="post" action="{{ route('tests.store') }}" data-run-test>@csrf<input type="hidden" name="submission_id" value="{{ $submission }}">
<div class="card-body">
<div class="row g-3">
<div class="col-md-6">
<label class="form-label" for="provider">AI provider</label>
<select id="provider" name="provider_id" class="form-select" required>@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach</select>
</div>
<div class="col-md-6">
<label class="form-label" for="model">AI model</label>
<select id="model" name="model" class="form-select">
<option>{{ $monitoringModel }}</option>
</select>
<div class="text-secondary small mt-1">Monitoring mode is configured for this workspace.</div>
</div>
<div class="col-12">
<label class="form-label" for="prompt">Search prompt</label>
<select id="prompt" name="prompt_id" class="form-select" required>@foreach($prompts as $prompt)<option value="{{ $prompt->id }}" @selected(old('prompt_id')==$prompt->id)>{{ $prompt->text }} · v{{ $prompt->version }}</option>@endforeach</select>
<div class="text-secondary small mt-1">The exact saved prompt and version are recorded with each test.</div>
</div>
<div class="col-12">
<label class="form-label" for="market">Market scenario</label>
<select id="market" name="market_id" class="form-select" required>@foreach($markets as $market)<option value="{{ $market->id }}">{{ $market->name }}</option>@endforeach</select>
<div class="text-secondary small mt-1">Country provides approximate search context; it does not simulate a local consumer account.</div>
</div>
</div>
<div class="alert alert-info mt-4 mb-0">{{ $simulation ? 'Each search uses simulated subscription credits only. No real provider request is sent.' : 'Each search uses your subscription credits. No automatic retries.' }}</div>
<p data-progress hidden role="status" class="mt-3 mb-0">{{ $simulation ? 'Generating a simulated result…' : 'Running your search… Please keep this page open.' }}</p>
</div>
<div class="card-footer d-flex justify-content-end">
<button class="btn btn-primary" type="submit" @disabled((!$simulation && !config('spending.execution_enabled')) || !$financialReady || app(\App\Services\CreditLedger::class)->balance()['remaining'] < app(\App\Services\CreditLedger::class)->weight(app(\App\Services\SimulatedMonitoring::class)->provider(),$monitoringModel) || (app(\App\Services\PlanCatalog::class)->current()['key']==='free' && !auth()->user()->hasVerifiedEmail()) || !$configured || $prompts->isEmpty() || $providers->isEmpty())><x-icon name="search" /> Run test</button>
</div>
</form>
</div>
@endsection
