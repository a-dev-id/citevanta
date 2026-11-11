@extends('layouts.app', ['title'=>'Dashboard'])
@section('actions')@if($workspace->canManage())<a href="{{ route('tests.create') }}" class="btn btn-primary"><x-icon name="plus" /> Run AI search test</a>@endif @endsection
@section('content')
@include('organizations.credits')
<p class="text-secondary">Visibility across completed, valid web-search tests. {{ $excluded }} failed, incomplete, invalid, or unapproved pilot results excluded.</p>
<div class="row row-deck row-cards mb-4">
@foreach([
 ['Total completed tests',$summary['total'], 'Valid tests included in this analysis'],
 [$workspace->shortName().' mention rate',$summary['mentionRate']!==null?$summary['mentionRate'].'%':'No data',$summary['mentioned'].' / '.$summary['total'].' valid tests'],
 [$workspace->shortName().' recommendation rate',$summary['recommendationRate']!==null?$summary['recommendationRate'].'%':'No data',$summary['recommended'].' / '.$summary['total'].' valid tests'],
 ['Official website citation rate',$summary['citationRate']!==null?$summary['citationRate'].'%':'No data',$summary['cited'].' / '.$summary['total'].' valid tests'],
 ['Average recommendation position',$summary['averagePosition']??'Not ranked',$summary['rankedTests'].' explicitly ranked '.$workspace->shortName().' recommendations'],
] as [$label,$value,$note])
<div class="col-sm-6 col-xl-4"><div class="card"><div class="card-body"><div class="subheader">{{ $label }}</div><div class="h1 my-3">{{ $value }}</div><div class="text-secondary small">{{ $note }}</div></div></div></div>
@endforeach
</div>
<div class="row row-cards mb-4"><div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="card-title">Most frequently recommended competitors</h2><a class="ms-auto" href="{{ route('visibility.competitors') }}">Compare businesses</a></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Business</th><th>Recommendations</th><th>Average position</th></tr></thead><tbody>@forelse($competitors as $row)<tr><td>{{ $row['hotel']->name }}</td><td>{{ $row['recommendations'] }} / {{ $summary['total'] }} tests</td><td>{{ $row['average_position']??'Not ranked' }}</td></tr>@empty<tr><td colspan="3" class="text-secondary p-4">No verified competitors detected in valid tests.</td></tr>@endforelse</tbody></table></div></div></div>
@if(app(\App\Services\PlanCatalog::class)->allows('advanced_improvements'))<div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="card-title">Improvement Opportunities</h2><a class="ms-auto" href="{{ route('improvements.index') }}">Review recommendations</a></div>
<div class="card-body"><div class="row g-3">@foreach(['open'=>'Open opportunities','high'=>'High priority','in_progress'=>'In progress','completed'=>'Completed'] as $key=>$label)<div class="col-6"><div class="subheader">{{ $label }}</div><div class="h2 mt-2 mb-0">{{ $improvementSummary[$key] }}</div></div>@endforeach</div></div>
<div class="list-group list-group-flush">@forelse($improvementSummary['recent'] as $item)<a class="list-group-item" href="{{ route('improvements.show',$item) }}">{{ $item->title }}<div class="small mt-1">{{ $item->evidence[0]['prompt']??'' }}</div><div class="text-secondary small mt-1">{{ \App\Models\Improvement::STATUSES[$item->status] }} &middot; {{ count($item->evidence) }} supporting tests</div></a>@empty<div class="card-body text-secondary">Generate recommendations from saved results on the Improvements page. No API request is needed.</div>@endforelse</div>
<div class="card-footer text-secondary small">Suggested actions are hypotheses. Counts reflect reviewed recommendations, not additional API tests.</div></div></div>@endif</div>
<div class="card"><div class="card-header"><h2 class="card-title">Recent tests</h2><a class="ms-auto" href="{{ route('tests.index') }}">View all tests</a></div><x-run-table :runs="$runs" /></div>
<p class="text-secondary small mt-3">A mention is not necessarily a recommendation. Ranks require explicit numbering; unnumbered lists remain unranked. Counts use one assessment per business per test. <a href="{{ route('visibility.hotels') }}">Review verified business identities</a>.</p>
@endsection
