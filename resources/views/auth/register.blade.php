<x-layouts::auth title="Create your account" subtitle="Try it free for 7 days with a limited scan of 15 questions on ChatGPT. No card needed.">
    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <x-form-field name="name" label="Your name" autocomplete="name" required autofocus />
        <x-form-field name="email" label="Work email" type="email" autocomplete="email" required :value="($audit ?? null)?->email" />
        <x-form-field name="team_name" label="Hotel or company name" autocomplete="organization" :value="($audit ?? null)?->hotel_name"
                      help="Used as your team name. You can change it later." />
        <x-form-field name="password" label="Password" type="password" autocomplete="new-password" required />
        <x-form-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

        {{-- Honeypot: hidden from people, filled by bots. --}}
        <div class="cv-hp" aria-hidden="true">
            <label for="register-fax">Fax</label>
            <input id="register-fax" name="fax" type="text" tabindex="-1" autocomplete="off">
        </div>
        <div class="mb-3"><x-site.turnstile /></div>

        <button type="submit" class="btn btn-primary w-100">Create account</button>
    </form>

    <x-slot:footer>
        Already have an account? <a href="{{ route('login') }}">Sign in</a>
    </x-slot:footer>
</x-layouts::auth>
