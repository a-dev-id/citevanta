<x-layouts::auth title="Confirm your password" subtitle="Please confirm your password to continue.">
    <form method="POST" action="{{ route('password.confirm') }}" novalidate>
        @csrf

        <x-form-field name="password" label="Password" type="password" autocomplete="current-password" required autofocus />

        <button type="submit" class="btn btn-primary w-100">Confirm</button>
    </form>
</x-layouts::auth>
