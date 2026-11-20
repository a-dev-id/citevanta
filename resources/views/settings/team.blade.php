<x-layouts::app title="Team settings" :breadcrumbs="['Settings' => null, 'Team' => null]">
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    <div class="row gx-3">
        <div class="col-xl-6">
            <x-card title="Team" :collapsible="false">
                <form id="team-form" method="POST" action="{{ route('settings.team.update') }}" novalidate>
                    @csrf
                    @method('PUT')
                    <x-form-field name="name" label="Team name" :value="$team->name" required :disabled="! $canUpdate" />

                    <div class="mb-3">
                        <label for="timezone" class="form-label">Timezone</label>
                        <select id="timezone" name="timezone" @class(['form-select', 'is-invalid' => $errors->has('timezone')])
                                aria-describedby="timezone-help" @disabled(! $canUpdate)>
                            @foreach ($timezones as $timezone)
                                <option value="{{ $timezone }}" @selected(old('timezone', $team->timezoneName()) === $timezone)>{{ $timezone }}</option>
                            @endforeach
                        </select>
                        <div id="timezone-help" class="form-text">Monthly manual scan allowances reset on the 1st in this timezone.</div>
                        @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </form>
                @if ($canUpdate)
                    <x-slot:footer>
                        <div class="text-end">
                            <button type="submit" form="team-form" class="btn btn-primary">Save team</button>
                        </div>
                    </x-slot:footer>
                @endif
            </x-card>
        </div>

        <div class="col-xl-6">
            <x-card title="Plan" :subtitle="$team->plan->label().($team->trial_ends_at ? ' · trial ends '.$team->trial_ends_at->setTimezone($team->timezoneName())->format('j M Y') : '')" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr><th scope="row">Hotels</th><td class="cv-num">{{ $limits->hotels }}</td></tr>
                        <tr><th scope="row">Prompts per hotel</th><td class="cv-num">{{ $limits->promptsPerHotel }}</td></tr>
                        <tr><th scope="row">Competitors</th><td class="cv-num">{{ $limits->competitors }}</td></tr>
                        <tr><th scope="row">Languages</th><td class="cv-num">{{ $limits->languages }}</td></tr>
                        <tr><th scope="row">Scheduled scans per month</th><td class="cv-num">{{ $limits->scansPerMonth }}</td></tr>
                        <tr><th scope="row">Manual scans per month</th><td class="cv-num">{{ $limits->manualScansPerMonth }}</td></tr>
                        <tr><th scope="row">Runs per prompt</th><td class="cv-num">{{ $limits->runsPerPrompt }}</td></tr>
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>

    <x-card title="Members" :flush="true" :collapsible="false">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th></tr></thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td class="fw-semibold">{{ $member->name }}</td>
                            <td>{{ $member->email }}</td>
                            <td><x-badge-status :label="ucfirst($member->pivot->role)" :variant="$member->pivot->role === 'owner' ? 'primary' : 'neutral'" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts::app>
