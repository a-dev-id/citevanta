<x-layouts::auth title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-form-field name="email" label="Email" type="email" :value="$request->email" autocomplete="email" required />
        <x-form-field name="password" label="New password" type="password" autocomplete="new-password" required autofocus />
        <x-form-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

        <button type="submit" class="btn btn-primary w-100">Reset password</button>
    </form>
</x-layouts::auth>
