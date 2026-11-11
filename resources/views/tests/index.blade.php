@extends('layouts.app', ['title'=>'Test History'])

@section('actions')@if($workspace->canManage())<a href="{{ route('tests.create') }}" class="btn btn-primary"><x-icon name="plus" /> Run AI search test</a>
@endif @endsection

@section('content')<div class="card">
<div class="card-header">
<form method="get" class="row g-2 w-100">
<div class="col-md-6">
<input name="q" class="form-control" value="{{ request('q') }}" placeholder="Search prompts" aria-label="Search prompts">
</div>
<div class="col-md-4">
<select name="status" class="form-select" aria-label="Filter by status">
<option value="">All statuses</option>@foreach(['completed','failed','incomplete','running','interrupted'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select>
</div>
<div class="col-auto">
<button class="btn" type="submit"><x-icon name="filter" /> Filter</button>
</div>
</form>
</div>
<x-run-table :runs="$runs" />
<div class="card-footer">{{ $runs->links() }}</div>
</div>
@endsection
