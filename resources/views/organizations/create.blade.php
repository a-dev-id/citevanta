@extends('layouts.app',['title'=>'Create organization'])
@section('content')
<div class="card"><div class="card-header"><h2 class="card-title">Organization and initial brand</h2></div><form method="post" action="{{ route('organizations.store') }}">@csrf<div class="card-body">
<p class="text-secondary">Authenticated platform setup only. You become the owner. The organization starts with the {{ ucfirst(config('plans.default')) }} plan and {{ number_format(config('plans.definitions.'.config('plans.default').'.monthly_searches')) }} monthly search credits. API execution requires platform approval; no payment is collected.</p>
<div class="mb-4"><label class="form-label" for="organization-name">Organization name</label><input class="form-control" id="organization-name" name="organization_name" required maxlength="180" value="{{ old('organization_name') }}"></div>
@include('organizations.brand-fields',['brand'=>null])
</div><div class="card-footer"><div class="btn-list"><button class="btn btn-primary" type="submit">Create organization</button><a class="btn" href="{{ route('organizations.index') }}">Cancel</a></div></div></form></div>
@endsection
