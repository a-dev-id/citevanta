<x-layouts::auth title="Forgot your password?" subtitle="Enter your email and we'll send you a reset link.">
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <x-form-field name="email" label="Email" type="email" autocomplete="email" required autofocus />

        <button type="submit" class="btn btn-primary w-100">Email reset link</button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}">Back to sign in</a>
    </x-slot:footer>
</x-layouts::auth>
