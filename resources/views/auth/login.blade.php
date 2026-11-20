<x-layouts::auth title="Sign in" subtitle="Welcome back. Sign in to see your hotel's AI visibility.">
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

        <x-form-field name="email" label="Email" type="email" autocomplete="email" required autofocus />
        <x-form-field name="password" label="Password" type="password" autocomplete="current-password" required />

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" @checked(old('remember'))>
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
            <a href="{{ route('password.request') }}" class="small">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i> Sign in
        </button>
    </form>

    <x-slot:footer>
        New to {{ config('app.name') }}? <a href="{{ route('register') }}">Create an account</a>
    </x-slot:footer>
</x-layouts::auth>
