@php
    $advanced = app(\App\Services\PlanCatalog::class)->allows('advanced_improvements');
    $plan = app(\App\Services\OrganizationQuota::class)->plan();
    $canTest = $workspace->canManage() && in_array('openai', $plan['providers'], true) && $plan['monthly_searches'] > 0;
    $workspacePage = request()->routeIs('organizations.*', 'brands.*');
    $section = request()->query('section', 'overview');
    $groups = [
        ['monitoring', 'Monitoring', 'search', [
            ['tests.create', 'Quick Test', [], request()->routeIs('tests.create'), $canTest],
            ['batches.index', 'Monitoring Campaigns', [], request()->routeIs('batches.*') && !request()->routeIs('batches.settings*') || request()->routeIs('pilot.*'), true],
            ['prompts.index', 'Search Prompts', [], request()->routeIs('prompts.*'), true],
        ]],
        ['results', 'Results', 'history', [
            ['tests.index', 'Test History', [], request()->routeIs('tests.index', 'tests.show', 'visibility.review'), true],
            ['visibility.competitors', 'Competitor Analysis', [], request()->routeIs('visibility.competitors', 'visibility.hotels*'), true],
        ]],
        ['improvements', 'Improvements', 'message-question', [
            ['visibility.opportunities', 'Opportunities', [], request()->routeIs('visibility.opportunities'), $advanced],
            ['improvements.index', 'Action Plan', [], request()->routeIs('improvements.*'), $advanced],
            ['organizations.index','Explore Pro features',['section'=>'usage'],false,!$advanced],
        ]],
        ['workspace', 'Workspace', 'users', [
            ['organizations.index', 'Organizations & Brands', ['section'=>'brands'], $workspacePage && !in_array($section, ['team','usage'], true), true],
            ['organizations.index', 'Team Members', ['section'=>'team'], $workspacePage && $section==='team', true],
            ['organizations.index', 'Plan & Usage', ['section'=>'usage'], $workspacePage && $section==='usage', true],
        ]],
        ['settings', 'Settings', 'settings', [
            ['settings', 'AI Settings', [], request()->routeIs('settings'), true],
        ]],
    ];
@endphp
<ul class="navbar-nav pt-lg-3" aria-label="Main navigation">
    <li class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
        <a class="nav-link" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
            <span class="nav-link-icon"><x-icon name="layout-dashboard" /></span><span class="nav-link-title">Dashboard</span>
        </a>
    </li>
    @foreach($groups as [$id, $label, $icon, $links])
        @php
            $links = collect($links)->filter(fn($link) => $link[4]);
            $expanded = $links->contains(fn($link) => $link[3]);
        @endphp
        @if($links->isNotEmpty())
            <li class="nav-item dropdown {{ $expanded ? 'active' : '' }}">
                <button class="nav-link dropdown-toggle w-100 {{ $expanded ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#nav-{{ $id }}" aria-controls="nav-{{ $id }}" aria-expanded="{{ $expanded ? 'true' : 'false' }}">
                    <span class="nav-link-icon"><x-icon :name="$icon" /></span><span class="nav-link-title">{{ $label }}</span>
                </button>
                <div class="collapse {{ $expanded ? 'show' : '' }}" id="nav-{{ $id }}">
                    <div class="dropdown-menu show">
                        @foreach($links as [$route, $text, $parameters, $active])
                            <a class="dropdown-item {{ $active ? 'active' : '' }}" href="{{ route($route, $parameters) }}" @if($active) aria-current="page" @endif>{{ $text }}</a>
                        @endforeach
                    </div>
                </div>
            </li>
        @endif
    @endforeach
</ul>
