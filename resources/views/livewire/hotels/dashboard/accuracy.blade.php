<x-card title="What AI gets wrong" :subtitle="$d->accuracy['open'] > 0 ? $d->accuracy['open'].' open '.Str::plural('issue', $d->accuracy['open']) : 'Claims that contradict your facts'" :flush="true">
    @if (! $d->accuracy['enabled'])
        <x-empty-state icon="fa-power-off" text="Accuracy checks are switched off." />
    @elseif ($d->accuracy['issues']->isEmpty())
        <x-empty-state icon="fa-circle-check" text="No open issues. AI assistants are not contradicting your facts." />
    @else
        @php($unlockedIssues = app(\App\Services\Trials\TrialLocks::class)->unlockedIssues($d->hotel))
        <ul class="list-unstyled mb-0 cv-issue-list">
            @foreach ($d->accuracy['issues'] as $issue)
                <li wire:key="dash-issue-{{ $issue->id }}">
                    <x-accuracy-issue :issue="$issue" :compact="true"
                                      :locked="$unlockedIssues !== null && ! in_array($issue->id, $unlockedIssues, true) ? \App\Services\Trials\TrialLocks::message($d->accuracy['open'], 'accuracy issues') : null"
                                      :lock-url="auth()->user()->ownsTeam($d->hotel->team) ? route('settings.billing') : null" />
                </li>
            @endforeach
        </ul>
    @endif

    <x-slot:footer>
        <a href="{{ route('hotels.accuracy', $d->hotel) }}">All accuracy issues</a>
        @if ($d->accuracy['missing'] > 0)
            <span class="text-muted ms-2">· {{ $d->accuracy['missing'] }} missing {{ Str::plural('fact', $d->accuracy['missing']) }} to add</span>
        @endif
    </x-slot:footer>
</x-card>
