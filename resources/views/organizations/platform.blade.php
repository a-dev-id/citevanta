@extends('layouts.app', ['title'=>'Platform administration'])
@section('content')
<div class="card"><div class="card-header"><h2 class="card-title">Organizations</h2></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Organization</th><th>Status</th><th>Subscription</th><th>Manage</th></tr></thead><tbody>@foreach($organizations as $organization)<tr><td>{{ $organization->name }}</td><td>{{ ucfirst($organization->status) }}</td><td>{{ ucfirst($organization->plan_key) }}</td><td><form method="post" action="{{ route('organizations.switch') }}">@csrf<input type="hidden" name="organization_id" value="{{ $organization->id }}"><button class="btn btn-sm" type="submit">Select organization</button></form></td></tr>@endforeach</tbody></table></div></div>
@endsection
