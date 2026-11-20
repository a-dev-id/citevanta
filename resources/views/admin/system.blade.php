@php
    $cron = $health['cron'];
    $queue = $health['queue'];
    $yesNo = fn (?bool $value) => $value === null ? 'Not needed (no limit)' : ($value ? 'Yes' : 'No');
@endphp

<x-layouts::app title="System" :breadcrumbs="['Admin' => null, 'System' => null]">
    <x-flash />

    @if ($budget['billing_stop'])
        <div class="alert alert-danger" role="alert">
            <p class="fw-semibold mb-1"><i class="fa-solid fa-circle-stop me-1" aria-hidden="true"></i> OpenAI credit or spend limit reached ({{ $budget['billing_stop']['code'] }}).</p>
            All AI calls are stopped and queued scans and audits are paused (not failed) since
            {{ \Illuminate\Support\Carbon::parse($budget['billing_stop']['at'])->setTimezone(config('citevanta.budget.timezone'))->format('j M Y, H:i') }}.
            Top up OpenAI or raise the limit, then click <strong>Resume paused jobs</strong>.
        </div>
    @endif

    <x-card title="AI budget" :subtitle="$budget['month'].' · resets on the 1st (Asia/Makassar)'" :flush="true" :collapsible="false">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th scope="col">Bucket</th><th scope="col" class="cv-num">Spent</th><th scope="col" class="cv-num">Cap</th><th scope="col" class="cv-num">Remaining</th><th scope="col" style="width: 30%">Used</th></tr>
                </thead>
                <tbody>
                    @foreach ($budget['rows'] as $row)
                        <tr @class(['fw-semibold' => $loop->first])>
                            <td>{{ $row['label'] }}</td>
                            <td class="cv-num">${{ number_format($row['spent'], 4) }}</td>
                            <td class="cv-num">${{ number_format($row['cap'], 2) }}</td>
                            <td class="cv-num">${{ number_format($row['remaining'], 4) }}</td>
                            <td>
                                <div class="progress" role="progressbar" aria-label="{{ $row['label'] }} used" aria-valuenow="{{ min(100, $row['percent']) }}" aria-valuemin="0" aria-valuemax="100">
                                    <div @class(['progress-bar', 'bg-success' => $row['percent'] < 50, 'bg-warning' => $row['percent'] >= 50 && $row['percent'] < 80, 'bg-danger' => $row['percent'] >= 80]) style="width: {{ min(100, $row['percent']) }}%"></div>
                                </div>
                                <span class="small text-muted">{{ $row['percent'] }}%</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <form method="POST" action="{{ route('admin.system.resume-paused') }}" class="d-flex flex-wrap align-items-center gap-2">
                @csrf
                <span class="small text-muted flex-grow-1">
                    {{ $budget['paused'] }} paused {{ Str::plural('job', $budget['paused']) }}.
                    Above 90% of the total only paid scheduled scans run. Caps: AI_BUDGET_TOTAL, AI_BUDGET_AUDITS, AI_BUDGET_TRIALS, AI_BUDGET_ADMIN.
                </span>
                <button type="submit" class="btn btn-sm btn-primary" @disabled(! $budget['can_resume'] || ($budget['paused'] === 0 && ! $budget['billing_stop']))>Resume paused jobs</button>
            </form>
        </x-slot:footer>
    </x-card>

    <x-card title="Measured AI cost" subtitle="From the API usage of every call: tokens (incl. search content) and web searches" :flush="true" :collapsible="false">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th scope="col"></th><th scope="col" class="cv-num">Last 7 days</th><th scope="col" class="cv-num">Last 30 days</th></tr>
                </thead>
                <tbody>
                    @php($money = fn ($value, $decimals = 4) => $value === null ? '—' : '$'.number_format($value, $decimals))
                    <tr><th scope="row">Per answer (answer + analysis)</th><td class="cv-num">{{ $money($budget['measured'][7]['per_answer'], 5) }}</td><td class="cv-num">{{ $money($budget['measured'][30]['per_answer'], 5) }}</td></tr>
                    <tr><th scope="row">Web searches per answer</th><td class="cv-num">{{ $budget['measured'][7]['searches_per_answer'] ?? '—' }}</td><td class="cv-num">{{ $budget['measured'][30]['searches_per_answer'] ?? '—' }}</td></tr>
                    <tr><th scope="row">Per free audit</th><td class="cv-num">{{ $money($budget['measured'][7]['per_audit']) }}</td><td class="cv-num">{{ $money($budget['measured'][30]['per_audit']) }}</td></tr>
                    <tr><th scope="row">Per trial</th><td class="cv-num">{{ $money($budget['measured'][7]['per_trial']) }}</td><td class="cv-num">{{ $money($budget['measured'][30]['per_trial']) }}</td></tr>
                    <tr><th scope="row">Per scan (incl. insights)</th><td class="cv-num">{{ $money($budget['measured'][7]['per_scan']) }}</td><td class="cv-num">{{ $money($budget['measured'][30]['per_scan']) }}</td></tr>
                    <tr><th scope="row">Answers measured</th><td class="cv-num">{{ $budget['measured'][7]['answers'] }}</td><td class="cv-num">{{ $budget['measured'][30]['answers'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($warnings !== [])
        <div class="alert alert-danger" role="alert">
            <p class="fw-semibold mb-1"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i> Fix before going live:</p>
            <ul class="mb-0">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (session('deploy_results'))
        @php($allOk = collect(session('deploy_results'))->every('ok'))
        <div @class(['alert', 'alert-success' => $allOk, 'alert-warning' => ! $allOk]) role="status">
            <p class="fw-semibold mb-2">{{ $allOk ? 'All tasks finished.' : 'Some tasks failed. See below.' }}</p>
            <ul class="mb-0 small">
                @foreach (session('deploy_results') as $result)
                    <li>
                        <i @class(['fa-solid me-1', 'fa-circle-check text-success' => $result['ok'], 'fa-circle-xmark text-danger' => ! $result['ok']]) aria-hidden="true"></i>
                        <code>{{ $result['task'] }}</code>: {{ $result['output'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($cron['warning'])
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>{{ $cron['warning'] }}.</strong>
                {{ $cron['last_run_at'] ? 'The last run was '.$cron['minutes_since_last_run'].' minutes ago.' : 'No cron run has been recorded yet.' }}
                Check the cron lines below.
            </div>
        </div>
    @endif

    <div class="row gx-3">
        <div class="col-xl-6">
            <x-card title="Cron" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr><th scope="row">Last run</th><td>{{ $cron['last_run_at'] ? \Carbon\CarbonImmutable::parse($cron['last_run_at'])->diffForHumans() : 'Never' }}</td></tr>
                        <tr><th scope="row">Mode used</th><td>{{ $cron['mode'] ?? '—' }}</td></tr>
                        <tr><th scope="row">Effective time limit</th><td>{{ $cron['effective_time_limit'] === null ? '—' : ($cron['effective_time_limit'] === 0 ? 'Unlimited' : $cron['effective_time_limit'].'s') }}</td></tr>
                        <tr><th scope="row">Work window</th><td>{{ $cron['work_window'] ? $cron['work_window'].'s' : '—' }}</td></tr>
                        <tr><th scope="row">Scheduler mode (last run)</th><td>{{ $cron['scheduler'] ?? '—' }}</td></tr>
                        <tr><th scope="row">Jobs processed (last run)</th><td>{{ $cron['last_jobs_processed'] ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="col-xl-6">
            <x-card title="PHP and queue" subtitle="Measured in this web request" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr><th scope="row">PHP version</th><td>{{ $health['php_version'] }}</td></tr>
                        <tr><th scope="row">memory_limit</th><td>{{ $health['memory_limit'] }}</td></tr>
                        <tr><th scope="row">set_time_limit works</th><td>{{ $yesNo($health['set_time_limit_works']) }}</td></tr>
                        <tr><th scope="row">proc_open available</th><td>{{ $health['proc_open_available'] ? 'Yes' : 'No' }} (scheduler: {{ $health['scheduler_mode'] }})</td></tr>
                        <tr><th scope="row">Queue driver</th><td>{{ $queue['driver'] }}</td></tr>
                        <tr><th scope="row">Pending jobs</th><td>{{ $queue['pending_jobs'] }}</td></tr>
                        <tr>
                            <th scope="row">Oldest pending job</th>
                            <td>{{ $queue['oldest_pending_seconds'] === null ? '—' : \Carbon\CarbonInterval::seconds($queue['oldest_pending_seconds'])->cascade()->forHumans(['short' => true]) }}</td>
                        </tr>
                        <tr>
                            <th scope="row">Failed jobs</th>
                            <td>{{ $queue['failed_jobs'] }} @if ($queue['failed_jobs'] > 0)<a href="{{ route('admin.failed-jobs') }}" class="ms-1">View</a>@endif</td>
                        </tr>
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>

    <x-card title="Scheduled tasks" subtitle="Times are in the task's timezone" :collapsible="false" :flush="true">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th scope="col">Task</th><th scope="col">Schedule</th><th scope="col">What it does</th><th scope="col">Last run</th></tr>
                </thead>
                <tbody>
                    @foreach ($health['scheduled_tasks'] as $task)
                        <tr>
                            <td><code>{{ $task['command'] }}</code></td>
                            <td><code>{{ $task['expression'] }}</code> <span class="text-muted small">{{ $task['timezone'] }}</span></td>
                            <td>{{ $task['description'] }}</td>
                            <td>{{ $task['last_run_at'] ? \Carbon\CarbonImmutable::parse($task['last_run_at'])->setTimezone($task['timezone'])->format('j M Y, H:i') : 'Never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Deploy tasks" subtitle="For hosts without SSH. Run after every upload of a new version." :collapsible="false">
        <p class="mb-2">
            Runs <code>migrate --force</code>, <code>config:cache</code>, <code>route:cache</code>, <code>view:cache</code>,
            <code>event:cache</code> and creates the storage link.
        </p>
        <p class="small text-muted">
            Environment: <strong>{{ $environment }}</strong> ·
            Public files served by: <strong>{{ $storageMethod === \App\Services\Deploy\DeployTasks::STORAGE_SYMLINK ? 'storage symlink' : '/storage route (no symlink)' }}</strong>
        </p>
        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.system.deploy-tasks') }}"
                  onsubmit="return confirm('Run the deploy tasks now? This migrates the database and rebuilds the caches.')">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-rocket me-1" aria-hidden="true"></i> Run deploy tasks</button>
            </form>
            <form method="POST" action="{{ route('admin.system.clear-caches') }}"
                  onsubmit="return confirm('Clear all caches (config, routes, views, events)?')">
                @csrf
                <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-broom me-1" aria-hidden="true"></i> Clear caches</button>
            </form>
        </div>
    </x-card>

    <x-card title="Cron lines" subtitle="Add one in cPanel > Cron Jobs, running every minute. Use the command line; the URL is the fallback." :collapsible="false">
        <label for="cron-command" class="form-label">1. Command cron (recommended)</label>
        <input id="cron-command" type="text" class="form-control font-monospace mb-3" readonly value="{{ $cronLines['command'] }}" onfocus="this.select()">

        <label for="cron-url" class="form-label">2. URL cron (fallback)</label>
        @if ($cronLines['url'])
            <input id="cron-url" type="text" class="form-control font-monospace" readonly value="{{ $cronLines['url'] }}" onfocus="this.select()">
            <div class="form-text">Contains the secret CRON_TOKEN. Do not share it.</div>
        @else
            <p class="mb-0 text-muted">Disabled: set CRON_TOKEN in .env (at least 40 random characters) to enable the URL cron.</p>
        @endif
    </x-card>
</x-layouts::app>
