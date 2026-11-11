@extends('layouts.app', ['title'=>$prompt->exists ? 'Edit prompt' : 'Add prompt'])

@section('content')<div class="card">
<form method="post" action="{{ $prompt->exists ? route('prompts.update',$prompt) : route('prompts.store') }}">@csrf @if($prompt->exists) @method('PUT') @endif<div class="card-body">
<div class="mb-3">
<label class="form-label" for="category">Category</label>
<input class="form-control" id="category" name="category" maxlength="100" required value="{{ old('category',$prompt->category) }}">
</div>
<div class="mb-3">
<label class="form-label" for="text">Exact search prompt</label>
<textarea class="form-control" id="text" name="text" rows="5" maxlength="4000" required>{{ old('text',$prompt->text) }}</textarea>
</div>
<input type="hidden" name="active" value="0">
<label class="form-check form-switch">
<input class="form-check-input" type="checkbox" name="active" value="1" @checked(old('active',$prompt->active))>
<span class="form-check-label">Active and available for tests</span>
</label>
<p class="text-secondary small mt-3 mb-0">Editing the text or category increments the version. Previous test snapshots remain available.</p>
</div>
<div class="card-footer d-flex gap-2 justify-content-end">
<a href="{{ route('prompts.index') }}" class="btn">Cancel</a>
<button class="btn btn-primary" type="submit"><x-icon name="device-floppy" /> Save prompt</button>
</div>
</form>
</div>
@endsection
