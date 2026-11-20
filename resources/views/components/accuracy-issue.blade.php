@props([
    'issue',
    // list<array{url: string, label: string}>
    'links' => [],
    'compact' => false,
    // Trial: only the badges and topic show, with this upgrade text (see TrialLocks).
    'locked' => null,
    'lockUrl' => null,
])

@php
    $verdictVariant = match ($issue->verdict) {
        \App\Enums\AccuracyVerdict::Wrong => 'danger',
        \App\Enums\AccuracyVerdict::Outdated => 'warning',
        default => 'neutral',
    };
@endphp

<div {{ $attributes->class(['cv-issue'])->merge(['id' => 'issue-'.$issue->id]) }}>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <x-badge-status :label="$issue->severity->label().' severity'" :variant="$issue->severity === \App\Enums\IssueSeverity::High ? 'danger' : 'warning'" />
        <x-badge-status :label="$issue->verdict->label()" :variant="$verdictVariant" />
        <strong>{{ $issue->fact_key }}</strong>
        @if ($issue->status !== \App\Enums\IssueStatus::Open)
            <x-badge-status :label="$issue->auto_resolved ? 'Looks fixed' : $issue->status->label()" :variant="$issue->status === \App\Enums\IssueStatus::Resolved ? 'success' : 'neutral'" />
        @endif
    </div>

    @if ($locked)
        <p class="small text-muted mb-0">
            <i class="fa-solid fa-lock me-1" aria-hidden="true"></i>
            @if ($lockUrl)<a href="{{ $lockUrl }}">{{ $locked }}</a>@else{{ $locked }}@endif
        </p>
    @else
    <dl class="cv-issue__facts mb-1">
        <dt>AI said</dt>
        <dd>{{ $issue->ai_claim }}</dd>
        <dt>Your facts</dt>
        <dd>{{ $issue->true_value ?? '—' }}</dd>
    </dl>

    @if ($issue->quote && ! $compact)
        <blockquote class="cv-issue__quote">“{{ $issue->quote }}”</blockquote>
    @endif

    <p class="small text-muted mb-0">
        Seen {{ $issue->occurrences }} {{ Str::plural('time', $issue->occurrences) }}
        @if ($issue->engines)
            in {{ collect($issue->engines)->map(fn ($e) => \App\Services\Dashboard\DashboardService::engineLabel($e))->implode(', ') }}
        @endif
        · last in scan #{{ $issue->last_seen_scan_id }}
        @foreach ($links as $link)
            · <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
        @endforeach
    </p>

    @endif

    @if ($issue->status === \App\Enums\IssueStatus::Dismissed && $issue->dismissed_reason)
        <p class="small text-muted mb-0">
            Dismissed by {{ $issue->dismissedBy?->name ?? 'a team member' }}
            {{ $issue->dismissed_at?->diffForHumans() }}: “{{ $issue->dismissed_reason }}”
        </p>
    @endif

    {{ $slot }}
</div>
