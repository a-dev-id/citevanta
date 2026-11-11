@extends('layouts.app', ['title'=>'Search Prompts'])

@section('actions')@if($workspace->canManage())<a class="btn btn-primary" href="{{ route('prompts.create') }}"><x-icon name="plus" /> Add prompt</a>
@endif @endsection

@section('content')<p class="text-secondary">Manage exact questions for repeatable searches. Saving a prompt never executes a test.</p>
<div class="card">
<div class="card-header">
<form class="row g-2 w-100" method="get">
<div class="col-md-6">
<input class="form-control" name="q" value="{{ request('q') }}" placeholder="Search prompts or categories" aria-label="Search prompts">
</div>
<div class="col-md-4">
<select class="form-select" name="active" aria-label="Filter by active status">
<option value="">All prompts</option>
<option value="1" @selected(request('active')==='1')>Active</option>
<option value="0" @selected(request('active')==='0')>Inactive</option>
</select>
</div>
<div class="col-auto">
<button class="btn" type="submit"><x-icon name="filter" /> Filter</button>
</div>
</form>
</div>
<div class="table-responsive">
<table class="table card-table">
<thead>
<tr>
<th>Prompt</th>
<th>Category</th>
<th>Version</th>
<th>Status</th>
<th>
</th>
</tr>
</thead>
<tbody>@forelse($prompts as $prompt)<tr>
<td class="prompt-cell">{{ $prompt->text }}<div class="text-secondary small">{{ $prompt->prompt_id }}</div>
</td>
<td>{{ $prompt->category }}</td>
<td>v{{ $prompt->version }}</td>
<td>
<span class="badge {{ $prompt->active ? 'bg-green-lt' : 'bg-secondary-lt' }}">{{ $prompt->active ? 'Active' : 'Inactive' }}</span>
</td>
<td>
@if($workspace->canManage())<a class="btn btn-sm" href="{{ route('prompts.edit',$prompt) }}">Edit</a>@endif
</td>
</tr>@empty<tr>
<td colspan="5" class="p-4 text-secondary">No prompts match your filters.</td>
</tr>@endforelse</tbody>
</table>
</div>
<div class="card-footer">{{ $prompts->links() }}</div>
</div>
@endsection
