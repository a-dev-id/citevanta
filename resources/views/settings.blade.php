@extends('layouts.app', ['title'=>'AI Settings'])

@section('content')<div class="card">
<div class="card-header">
<h2 class="card-title">Monitoring connection</h2>
</div>
<div class="card-body">
<dl>
<dt>API key</dt>
<dd>
<span class="badge {{ $configured ? 'bg-green-lt' : 'bg-yellow-lt' }}">{{ $configured ? 'Configured on server' : 'Not configured' }}</span>
</dd>
<dt>Model</dt>
<dd>{{ app(\App\Services\SimulatedMonitoring::class)->model() }}</dd>
<dt>Daily test limit</dt>
<dd>{{ config('intelligence.daily_limit') }}</dd>
<dt>Execution</dt>
<dd>Manual · One request per test · No automatic retries</dd>
<dt>Search context</dt>
<dd>Approximate country and response language. This does not represent every consumer AI experience.</dd>
<dt>Monitored property official websites</dt>
<dd>{{ implode(', ', $workspace->hotel()->official_websites) }}</dd>
</dl>
<p class="text-secondary mb-0">Your AI connection is managed securely by the platform. Review <a href="{{ route('organizations.index',['section'=>'usage']) }}">Plan &amp; Usage</a> for monthly credits and workspace allowances.</p>
</div>
</div>

@endsection
