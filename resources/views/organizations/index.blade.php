@extends('layouts.app', ['title'=>match($section) { 'brands'=>'Organizations & Brands', 'team'=>'Team Members', 'usage'=>'Plan & Usage', default=>'Organization Settings' }])
@section('actions')
@if(in_array($section,['overview','brands'],true))@can('create',\App\Models\Organization::class)<a class="btn" href="{{ route('organizations.create') }}"><x-icon name="plus" /> Create organization</a>@endcan @endif
@endsection
@section('content')
<p class="text-secondary">{{ $organization->name }} &middot; {{ ucfirst($organization->status) }} &middot; Your role: {{ app(\App\Services\TenantContext::class)->roleLabel(auth()->user()) }}. Settings and usage apply only to this organization.</p>
@if(in_array($section,['overview','usage'],true))@include('organizations.usage')@endif
@if(in_array($section,['overview','brands'],true))<div class="row row-cards mb-4">@include('organizations.details')</div>@endif
@if(in_array($section,['overview','team'],true))@include('organizations.team')@endif
@if(in_array($section,['overview','brands'],true))@include('organizations.brands')@endif
@endsection
