<x-layouts::auth title="Verify your email"
                 :subtitle="'We sent a verification link to your email address. Click it to start using '.config('app.name').'.'">
    @if (session('status') === 'verification-link-sent')
        <div class="alert alert-success" role="status">A new verification link has been sent.</div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mb-2">
        @csrf
        <button type="submit" class="btn btn-primary w-100">Resend verification email</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-link w-100">Log out</button>
    </form>
</x-layouts::auth>
