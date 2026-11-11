@extends('layouts.app', ['title' => 'Recommendation review'])
@section('actions')<a class="btn" href="{{ route('improvements.index') }}"><x-icon name="arrow-left" /> Improvements</a>@endsection
@section('content')
<div class="row row-cards">
<div class="col-lg-8">
<div class="card mb-4"><div class="card-header"><h2 class="card-title">{{ $improvement->title }}</h2></div><div class="card-body">
<div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-primary-lt">{{ $improvement->category }}</span><span class="badge bg-yellow-lt">Hypothesis</span><span class="badge bg-secondary-lt">{{ ucfirst($improvement->priority) }} priority</span><span class="badge bg-secondary-lt">{{ ucfirst($improvement->confidence) }} confidence</span></div>
<p>{{ $improvement->explanation }}</p>
@unless($improvement->active)<div class="alert alert-warning">This condition was not observed in the latest refresh. Historical evidence and review records are retained.</div>@endunless
<h3 class="h4">Suggested action</h3><ul>@foreach($improvement->actions as $action)<li>{{ $action }}</li>@endforeach</ul>
<h3 class="h4">Target website page</h3>
@if($improvement->verified_target_url)<a class="text-break" href="{{ $improvement->verified_target_url }}" target="_blank" rel="noopener noreferrer">{{ $improvement->verified_target_url }}</a><p class="text-secondary small mt-1">From the workspace's previously verified official-page registry. Page content has not been audited for a gap.</p>@else<p class="text-secondary">No relevant target page verified. Verify a page before proposing changes.</p>@endif
@if($improvement->target_url)<p class="mt-2 mb-0">Administrator target (not automatically verified): <a class="text-break" href="{{ $improvement->target_url }}" target="_blank" rel="noopener noreferrer">{{ $improvement->target_url }}</a></p>@endif
<p class="text-secondary small mb-0 mt-3">Created {{ $improvement->created_at->format('d M Y, H:i') }} UTC. Evidence refreshed {{ $improvement->refreshed_at->format('d M Y, H:i') }} UTC. @if($improvement->completed_at)Completed {{ $improvement->completed_at->format('d M Y, H:i') }} UTC.@endif</p>
</div></div>
<div class="card mb-4"><div class="card-header"><h2 class="card-title">Supporting evidence</h2></div><div class="card-body">
<p class="text-secondary small">Saved observations at refresh time. Categories use current prompt metadata; original prompt text and version are preserved. Excerpts show the first 1,400 characters. Open the original for complete context.</p>
@foreach($improvement->evidence as $e)<section class="{{ $loop->first?'':'border-top pt-3 mt-3' }}"><h3 class="h4">{{ $e['prompt'] }}</h3><p class="text-secondary small">{{ $e['category'] }} &middot; Prompt version {{ $e['prompt_version'] }} &middot; Test #{{ $e['test_id'] }}</p>
<p>Brand {{ $e['mentioned']?'mentioned':'absent' }}; {{ $e['recommended']?'recommended':'not recommended' }}; official website {{ $e['official_cited']?'cited':'not cited' }}.</p>
<h4 class="h5">Relevant competitors</h4><ul>@forelse($e['competitors'] as $c)<li>{{ $c['name'] }}: {{ $c['recommended']?'recommended':'mentioned only' }}@if($c['rank']!==null), position {{ $c['rank'] }}@endif; {{ $c['official_cited']?'supporting official citation attached':'no supporting official citation attached' }}.</li>@empty<li>No verified competing hotels detected.</li>@endforelse</ul>
<details class="mb-3"><summary>Original answer excerpt</summary><div class="answer mt-2">{{ $e['excerpt'] }}</div></details>
<div class="btn-list"><a class="btn btn-sm" href="{{ route('tests.show',$e['test_id']) }}">Original test #{{ $e['test_id'] }} <x-icon name="arrow-right" /></a><a class="btn btn-sm" href="{{ route('visibility.review',$e['test_id']) }}">Review analysis</a></div>
</section>@endforeach</div></div>
<div class="card"><div class="card-header"><h2 class="card-title">Optional AI explanation</h2></div><div class="card-body">
<p>Explicit approval sends up to 20 saved evidence records to OpenAI, with no web search. AI selects supporting tests and permitted actions; CiteVanta renders evidence-constrained wording to prevent unsupported factual claims.</p>
<p class="text-secondary">Required credits: {{ app(\App\Services\CreditLedger::class)->weight('openai',config('intelligence.model')) }} for one explanation.</p> 
@if($blocked)<div class="alert alert-warning">{{ \App\Services\MonitoringMessages::publicText($blocked) }} Contact support if monitoring remains unavailable.</div>@endif
@if($workspace->canManage())<form method="post" action="{{ route('improvements.explain',$improvement) }}">@csrf<input type="hidden" name="submission_id" value="{{ $submission }}"><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="acknowledge" value="1" required @disabled($blocked)><span class="form-check-label">I approve using the required credits for one explanation.</span></label><button type="submit" class="btn btn-primary" @disabled($blocked)>Generate AI explanation</button></form>@endif
@foreach($improvement->explanations->sortByDesc('created_at') as $explanation)<div class="border-top pt-3 mt-3"><div class="d-flex gap-2 flex-wrap align-items-center"><h3 class="h4 mb-0">AI-generated explanation</h3><x-status-badge :status="$explanation->status" /></div><p class="text-secondary small mt-2">{{ $explanation->created_at->format('d M Y, H:i') }} UTC &middot; {{ $explanation->model }} </p>
@if($explanation->text)<div class="answer">{{ $explanation->text }}</div>@endif
@if($explanation->error_details)<p class="text-danger">{{ \App\Services\MonitoringMessages::publicText($explanation->error_details) }}</p>@endif
@if($explanation->status==='running')<p class="text-secondary">Request outcome may still be unknown. It will not be sent again automatically.</p>@endif

</div>@endforeach
</div></div>
</div>
<div class="col-lg-4"><div class="card mb-4"><div class="card-header"><h2 class="card-title">Review and tracking</h2></div><div class="card-body">@if($workspace->canManage())<form method="post" action="{{ route('improvements.update',$improvement) }}">@csrf @method('put')
<div class="mb-3"><label class="form-label" for="status">Status</label><select id="status" name="status" class="form-select">@foreach(\App\Models\Improvement::STATUSES as $value=>$label)<option value="{{ $value }}" @selected(old('status',$improvement->status)===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="mb-3"><label class="form-label" for="target_url">Optional target URL</label><input class="form-control" type="url" id="target_url" name="target_url" value="{{ old('target_url',$improvement->target_url) }}" maxlength="2048" placeholder="https://"><div class="form-hint">Administrator supplied; not marked as verified.</div></div>
<div class="mb-3"><label class="form-label" for="notes">Administrator notes</label><textarea class="form-control" id="notes" name="notes" rows="6" maxlength="10000">{{ old('notes',$improvement->notes) }}</textarea></div><button class="btn btn-primary" type="submit"><x-icon name="device-floppy" /> Save review</button></form>@endif</div></div>
<div class="card"><div class="card-header"><h2 class="card-title">Review history</h2></div><div class="list-group list-group-flush">@foreach($improvement->events->sortByDesc('id') as $event)<div class="list-group-item"><div>{{ $event->from_status?\App\Models\Improvement::STATUSES[$event->from_status].' to ':'' }}{{ \App\Models\Improvement::STATUSES[$event->to_status] }}</div><div class="text-secondary small mt-1">{{ $event->user->name }} &middot; {{ $event->created_at->format('d M Y, H:i') }} UTC</div>@if($event->note)<p class="text-break mt-2 mb-0">{{ $event->note }}</p>@endif</div>@endforeach</div></div></div>
</div>
@endsection
