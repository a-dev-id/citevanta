@if (session('status'))
    <div class="alert alert-success" role="status">
        <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ session('status') }}
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i> {{ session('error') }}
    </div>
@endif
