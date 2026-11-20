{{--
    Design preview: every layout piece and component with static sample data.
    Local environment only (see routes/web.php). Add ?sidebar=collapsed to see the rail.
--}}
@php
    use App\Services\Navigation\ShellData;
    use App\Services\Navigation\SidebarMenu;

    $previewUser = (new \App\Models\User)->forceFill(['name' => 'Jane Cooper', 'is_admin' => true]);

    $shell = new ShellData(
        user: ['name' => 'Jane Cooper', 'initials' => 'JC', 'role' => 'Owner'],
        hotels: [
            ['name' => 'Alaya Resort Ubud', 'url' => '#', 'active' => true],
            ['name' => 'Alaya Dedari Kumarahouse', 'url' => '#', 'active' => false],
            ['name' => 'Alaya Jembawan', 'url' => '#', 'active' => false],
        ],
        notifications: [
            ['icon' => 'fa-circle-check', 'variant' => 'success', 'text' => 'Scan #12 completed: visibility 42%', 'time' => '12 minutes ago'],
            ['icon' => 'fa-triangle-exclamation', 'variant' => 'danger', 'text' => '3 answers could not be analysed', 'time' => 'Yesterday'],
        ],
        menu: app(SidebarMenu::class)->sections($previewUser, 'dashboard'),
    );

    $personas = [
        ['Family', 6, 3, 50.0], ['Business', 6, 1, 16.7], ['Leisure', 6, 4, 66.7],
        ['Adventure', 6, 2, 33.3], ['Couple', 6, 5, 83.3],
    ];
    $shareOfVoice = [
        ['Maya Ubud Resort', 18, 60.0, 34.0, false],
        ['Alaya Resort Ubud', 15, 50.0, 28.3, true],
        ['Kayon Jungle Resort', 12, 40.0, 22.6, false],
        ['Komaneka at Bisma', 8, 26.7, 15.1, false],
    ];
    $domains = [
        ['tripadvisor.com', 24, false], ['booking.com', 17, false], ['alayahotels.com', 9, true],
        ['agoda.com', 8, false], ['cntraveler.com', 5, false],
    ];
    $scans = [
        ['#14', 'running', 'Today, 09:12', '18 / 60', null],
        ['#13', 'completed', '12 Nov 2026', '60', 0.42],
        ['#12', 'completed_with_errors', '28 Oct 2026', '57', 0.39],
        ['#11', 'failed', '14 Oct 2026', '0', 0.00],
    ];
    $swatches = [
        'Primary' => '#0088cc', 'Success' => '#47a447', 'Warning' => '#ed9c28', 'Danger' => '#d2322d',
        'Info' => '#5bc0de', 'Header' => '#171717', 'Sidebar' => '#1d2127', 'Content' => '#ecedf0',
    ];
@endphp

<x-layouts::app title="Design preview"
                :breadcrumbs="['Design' => null, 'Preview' => null]"
                :shell="$shell"
                :html-class="match (request('sidebar')) { 'collapsed' => 'sidebar-collapsed', 'open' => 'sidebar-open', default => null }">
    <x-slot:pageActions>
        <span class="d-none d-md-inline">Last scan: 12 Nov 2026</span>
        <button type="button" class="btn btn-sm btn-primary">
            <i class="fa-solid fa-bolt me-1" aria-hidden="true"></i> Scan now
        </button>
    </x-slot:pageActions>

    <div class="alert alert-info d-flex align-items-center gap-2" role="status">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div>
            Static sample data, local environment only.
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-cv-sidebar-toggle>Toggle the sidebar</button>
            to see the collapsed rail (the state is remembered), or open
            <a href="{{ url('/design-preview?sidebar=collapsed') }}">?sidebar=collapsed</a>.
        </div>
    </div>

    {{-- Scan running banner --}}
    <div class="cv-scan-banner" role="status">
        <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span>
        <div><strong>Scan running</strong> · 18 of 60 answers collected. Results update automatically.</div>
    </div>

    {{-- Summary widgets --}}
    <div class="row gx-3">
        <div class="col-sm-6 col-xl-3">
            <x-widget-summary title="Overall visibility" value="42%" icon="fa-eye" variant="primary" :change="6.5" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-widget-summary title="Average position" value="#2.4" icon="fa-ranking-star" variant="info" :change="-0.6" change-unit="places" :lower-is-better="true" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-widget-summary title="Positive sentiment" value="71%" icon="fa-face-smile" variant="success" :change="-4" />
        </div>
        <div class="col-sm-6 col-xl-3">
            <x-widget-summary title="Cited sources" value="23" icon="fa-link" variant="warning" :change="null" />
        </div>
    </div>

    <div class="row gx-3">
        <div class="col-xl-7">
            <x-card title="Visibility by persona" subtitle="Share of answers that mention your hotel" :refresh="true" :flush="true">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr><th scope="col">Persona</th><th scope="col" class="cv-num">Answers</th><th scope="col" class="cv-num">Mentions</th><th scope="col" class="cv-num">Visibility</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($personas as [$persona, $answers, $mentions, $pct])
                                <tr>
                                    <td><x-badge-status :label="$persona" variant="primary" /></td>
                                    <td class="cv-num">{{ $answers }}</td>
                                    <td class="cv-num">{{ $mentions }}</td>
                                    <td class="cv-num">
                                        <div class="cv-progress-cell">
                                            <div class="progress" role="progressbar" aria-label="{{ $persona }} visibility" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar" style="width: {{ $pct }}%"></div>
                                            </div>
                                            <span>{{ $pct }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="col-xl-5">
            <x-card title="Competitor share of voice" :flush="true">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th scope="col">Hotel</th><th scope="col" class="cv-num">Mentions</th><th scope="col" class="cv-num">Share of voice</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($shareOfVoice as [$name, $mentions, $visibility, $share, $isYou])
                                <tr @class(['is-highlighted' => $isYou])>
                                    <td>{{ $name }} @if ($isYou)<span class="text-muted fw-normal">(you)</span>@endif</td>
                                    <td class="cv-num">{{ $mentions }}</td>
                                    <td class="cv-num">{{ $share }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="col-xl-7">
            <x-card title="Visibility trend" subtitle="Charts arrive in Milestone 3">
                <div class="cv-chart-placeholder">
                    <span><i class="fa-solid fa-chart-line me-1" aria-hidden="true"></i> Chart area</span>
                </div>
            </x-card>
        </div>

        <div class="col-xl-5">
            <x-card title="Top cited domains" :flush="true">
                <x-slot:actions>
                    <x-badge-status label="Your website cited" variant="success" icon="fa-circle-check" class="me-1" />
                </x-slot:actions>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th scope="col">Domain</th><th scope="col" class="cv-num">Citations</th></tr></thead>
                        <tbody>
                            @foreach ($domains as [$domain, $count, $own])
                                <tr @class(['is-highlighted' => $own])>
                                    <td>{{ $domain }} @if ($own)<span class="text-muted fw-normal">(your website)</span>@endif</td>
                                    <td class="cv-num">{{ $count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>

    {{-- Prompt results accordion --}}
    <x-card title="Prompt results" subtitle="Expand a prompt to read the AI answers" :flush="true">
        <div class="accordion accordion-flush" id="preview-prompts">
            @foreach ([
                ['What is the best family-friendly resort in Ubud with a pool?', 'Family', '67%'],
                ['Which resort in Ubud is best for business travellers?', 'Business', '0%'],
                ['What is the most romantic resort in Ubud for a honeymoon?', 'Couple', '100%'],
            ] as $i => [$prompt, $persona, $visibility])
                <div class="accordion-item">
                    <h3 class="accordion-header">
                        <button @class(['accordion-button', 'collapsed' => $i > 0]) type="button" data-bs-toggle="collapse"
                                data-bs-target="#preview-prompt-{{ $i }}" aria-expanded="{{ $i === 0 ? 'true' : 'false' }}"
                                aria-controls="preview-prompt-{{ $i }}">
                            <span class="flex-grow-1">{{ $prompt }}</span>
                            <x-badge-status :label="$persona" variant="primary" />
                            <strong class="ms-2 me-2">{{ $visibility }}</strong>
                        </button>
                    </h3>
                    <div id="preview-prompt-{{ $i }}" @class(['accordion-collapse collapse', 'show' => $i === 0]) data-bs-parent="#preview-prompts">
                        <div class="accordion-body">
                            <p class="small text-muted mb-2">Run 1 · OpenAI · positive</p>
                            <div class="cv-answer">Here are 4 well-reviewed places to stay in Ubud for families:

1. <mark>Alaya Resort Ubud</mark> - Guests consistently praise a large kids club and a shallow lagoon pool.
2. Maya Ubud Resort - Offers family suites with connecting rooms at a mid-range price point.
3. Kayon Jungle Resort - Has babysitting on request, however some recent reviews mention dated rooms.</div>
                            <p class="small mb-1 fw-semibold">Citations</p>
                            <ul class="small mb-0">
                                <li><a href="#">tripadvisor.com/Hotel_Review-alaya-resort-ubud <i class="fa-solid fa-arrow-up-right-from-square fa-xs" aria-hidden="true"></i></a></li>
                                <li><a href="#">alayahotels.com <i class="fa-solid fa-arrow-up-right-from-square fa-xs" aria-hidden="true"></i></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-card>

    {{-- Scan history --}}
    <x-card title="Scan history" :flush="true">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr><th scope="col">Scan</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col" class="cv-num">Answers</th><th scope="col" class="cv-num">Cost</th></tr>
                </thead>
                <tbody>
                    @foreach ($scans as [$id, $status, $date, $answers, $cost])
                        <tr>
                            <td><a href="#">Scan {{ $id }}</a></td>
                            <td><x-badge-status :status="$status" /></td>
                            <td>{{ $date }}</td>
                            <td class="cv-num">{{ $answers }}</td>
                            <td class="cv-num">{{ $cost === null ? '—' : '$'.number_format($cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <a href="#">View all scans</a>
        </x-slot:footer>
    </x-card>

    <div class="row gx-3">
        {{-- Buttons & badges --}}
        <div class="col-xl-6">
            <x-card title="Buttons and badges">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary">Primary</button>
                    <button type="button" class="btn btn-success">Success</button>
                    <button type="button" class="btn btn-warning">Warning</button>
                    <button type="button" class="btn btn-danger">Danger</button>
                    <button type="button" class="btn btn-info">Info</button>
                    <button type="button" class="btn btn-dark">Dark</button>
                    <button type="button" class="btn btn-light border">Light</button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary">Outline</button>
                    <button type="button" class="btn btn-outline-secondary">Secondary</button>
                    <button type="button" class="btn btn-primary btn-sm">Small</button>
                    <button type="button" class="btn btn-primary btn-lg">Large</button>
                    <button type="button" class="btn btn-primary" disabled>Disabled</button>
                    <button type="button" class="btn btn-primary" disabled>
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Scanning…
                    </button>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @foreach (['queued', 'running', 'completed', 'completed_with_errors', 'failed'] as $status)
                        <x-badge-status :status="$status" />
                    @endforeach
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @foreach (['positive', 'neutral', 'negative', 'not_mentioned'] as $sentiment)
                        <x-badge-status :status="$sentiment" />
                    @endforeach
                    <x-badge-status label="Family" variant="primary" />
                    <x-badge-status label="Soon" variant="neutral" />
                </div>
            </x-card>
        </div>

        {{-- Forms --}}
        <div class="col-xl-6">
            <x-card title="Forms">
                <form onsubmit="return false" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="pv-name" class="form-label">Hotel name</label>
                            <input id="pv-name" type="text" class="form-control" value="Alaya Resort Ubud">
                        </div>
                        <div class="col-md-6">
                            <label for="pv-type" class="form-label">Hotel type</label>
                            <select id="pv-type" class="form-select">
                                <option>Resort</option><option>Boutique hotel</option><option>Villa</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="pv-website" class="form-label">Website</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                                <input id="pv-website" type="url" class="form-control" placeholder="https://">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="pv-prompt" class="form-label">Prompt</label>
                            <input id="pv-prompt" type="text" class="form-control is-invalid" value="Is Alaya Resort Ubud good?" aria-describedby="pv-prompt-error">
                            <div id="pv-prompt-error" class="invalid-feedback">Prompts must not contain the hotel's own name.</div>
                        </div>
                        <div class="col-12">
                            <label for="pv-notes" class="form-label">Notes</label>
                            <textarea id="pv-notes" class="form-control" rows="2"></textarea>
                            <div class="form-text">Shown to your team only.</div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pv-check" checked>
                                <label class="form-check-label" for="pv-check">Kids club</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="pv-switch" checked>
                                <label class="form-check-label" for="pv-switch">Active</label>
                            </div>
                        </div>
                    </div>
                </form>
                <x-slot:footer>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary">Cancel</button>
                        <button type="button" class="btn btn-primary">Save</button>
                    </div>
                </x-slot:footer>
            </x-card>
        </div>

        {{-- Alerts --}}
        <div class="col-xl-6">
            <x-card title="Alerts">
                <div class="alert alert-success" role="alert"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> Scan queued. Results appear in a few minutes.</div>
                <div class="alert alert-info" role="alert"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i> Your trial ends in 5 days.</div>
                <div class="alert alert-warning" role="alert"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i> You have reached the prompt limit for your plan.</div>
                <div class="alert alert-danger mb-0" role="alert"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i> The last scan failed. Try again later.</div>
            </x-card>
        </div>

        {{-- Empty state + skeleton --}}
        <div class="col-xl-6">
            <x-card title="Empty state">
                <x-empty-state icon="fa-satellite-dish" text="No scans yet. Run your first scan to see how AI assistants recommend your hotel." action-label="Run your first scan" action-icon="fa-bolt" />
            </x-card>
        </div>

        <div class="col-xl-6">
            <x-card title="Loading skeleton">
                <div aria-busy="true">
                    <span class="cv-skeleton cv-skeleton--title"></span>
                    <span class="cv-skeleton"></span>
                    <span class="cv-skeleton w-75"></span>
                    <span class="cv-skeleton w-50 mb-0"></span>
                    <span class="visually-hidden">Loading</span>
                </div>
            </x-card>
        </div>

        <div class="col-xl-6">
            <x-widget-summary title="Overall visibility" value="—" :loading="true" />
            <x-card title="Collapsed card" :collapsed="true">
                Hidden until expanded.
            </x-card>
        </div>

        {{-- Tokens --}}
        <div class="col-12">
            <x-card title="Colours and type" subtitle="All values live in resources/sass/_variables.scss">
                <div class="row g-3 mb-3">
                    @foreach ($swatches as $name => $hex)
                        <div class="col-6 col-md-3 col-xl">
                            <span class="cv-swatch" style="background: {{ $hex }}"></span>
                            <div class="small mt-1"><strong>{{ $name }}</strong> <span class="text-muted">{{ $hex }}</span></div>
                        </div>
                    @endforeach
                </div>
                <h2 class="h4 mb-1">Heading in Open Sans</h2>
                <p class="mb-1">Body text is 13px Open Sans for menus and tables. <a href="#">Links</a> use the primary colour.</p>
                <p class="text-muted mb-0">Muted helper text.</p>
            </x-card>
        </div>
    </div>
</x-layouts::app>
