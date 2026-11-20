<x-layouts::app title="Profile" :breadcrumbs="['Settings' => null, 'Profile' => null]">
    @if (session('status') === 'profile-information-updated')
        <div class="alert alert-success" role="status">Profile saved.</div>
    @elseif (session('status') === 'password-updated')
        <div class="alert alert-success" role="status">Password changed.</div>
    @elseif (session('status') === 'notifications-updated')
        <div class="alert alert-success" role="status">Email settings saved.</div>
    @endif

    <div class="row gx-3">
        <div class="col-xl-6">
            <x-card title="Profile information" :collapsible="false">
                <form id="profile-form" method="POST" action="{{ route('user-profile-information.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <x-form-field name="name" label="Name" :value="$user->name" bag="updateProfileInformation" autocomplete="name" required />
                    <x-form-field name="email" label="Email" type="email" :value="$user->email" bag="updateProfileInformation" autocomplete="email" required
                                  help="Changing your email asks you to verify the new address." />
                </form>
                <x-slot:footer>
                    <div class="text-end">
                        <button type="submit" form="profile-form" class="btn btn-primary">Save profile</button>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        <div class="col-xl-6">
            <x-card title="Change password" :collapsible="false">
                <form id="password-form" method="POST" action="{{ route('user-password.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <x-form-field name="current_password" label="Current password" type="password" bag="updatePassword" autocomplete="current-password" required />
                    <x-form-field name="password" label="New password" type="password" bag="updatePassword" autocomplete="new-password" required />
                    <x-form-field name="password_confirmation" label="Confirm new password" type="password" bag="updatePassword" autocomplete="new-password" required />
                </form>
                <x-slot:footer>
                    <div class="text-end">
                        <button type="submit" form="password-form" class="btn btn-primary">Change password</button>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
    <div class="row gx-3">
        <div class="col-xl-6">
            <x-card title="Email" :collapsible="false">
                <form id="notifications-form" method="POST" action="{{ route('settings.notifications') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="weekly_report" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="weekly_report" name="weekly_report" value="1" @checked($weeklyReport) aria-describedby="weekly-report-help">
                        <label class="form-check-label" for="weekly_report">Weekly report</label>
                    </div>
                    <div id="weekly-report-help" class="form-text">Every Monday at 08:00 (team timezone), when there are new scan results. On by default for owners.</div>
                </form>
                <x-slot:footer>
                    <div class="text-end">
                        <button type="submit" form="notifications-form" class="btn btn-primary">Save email settings</button>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>
    </div>
</x-layouts::app>
