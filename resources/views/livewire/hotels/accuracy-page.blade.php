<div>
    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
    @endif

    <x-card title="What AI gets wrong" subtitle="Claims AI assistants make about {{ $hotel->name }} that your facts contradict ({{ $openCount }} open)" :collapsible="false">
        <form class="row g-2 mb-3" aria-label="Filter issues" wire:submit.prevent>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small" for="filter-status">Status</label>
                <select id="filter-status" class="form-select form-select-sm" wire:model.live="status">
                    <option value="open">Open</option>
                    <option value="resolved">Resolved</option>
                    <option value="dismissed">Dismissed</option>
                    <option value="all">All</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small" for="filter-severity">Severity</label>
                <select id="filter-severity" class="form-select form-select-sm" wire:model.live="severity">
                    <option value="">Any</option>
                    @foreach (\App\Enums\IssueSeverity::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small" for="filter-verdict">Verdict</label>
                <select id="filter-verdict" class="form-select form-select-sm" wire:model.live="verdict">
                    <option value="">Wrong or outdated</option>
                    <option value="wrong">Wrong</option>
                    <option value="outdated">Outdated</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small" for="filter-engine">AI assistant</label>
                <select id="filter-engine" class="form-select form-select-sm" wire:model.live="engine">
                    <option value="">All</option>
                    @foreach ($engines as $option)
                        <option value="{{ $option }}">{{ \App\Services\Dashboard\DashboardService::engineLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        @forelse ($issues as $issue)
            @php($isLocked = $unlocked !== null && ! in_array($issue->id, $unlocked, true))
            <x-accuracy-issue :issue="$issue" :links="$isLocked ? [] : ($links[$issue->id] ?? [])" wire:key="issue-{{ $issue->id }}" class="cv-issue--row"
                              :locked="$isLocked ? \App\Services\Trials\TrialLocks::message($openCount, 'accuracy issues') : null" :lock-url="$lockUrl">
                <div class="mt-2">
                    @if ($dismissing === $issue->id)
                        <form wire:submit="dismiss" class="cv-issue__dismiss">
                            <label class="form-label small" for="dismiss-reason-{{ $issue->id }}">Why is this not a problem?</label>
                            <textarea id="dismiss-reason-{{ $issue->id }}" rows="2" maxlength="500" wire:model="reason"
                                      @class(['form-control form-control-sm', 'is-invalid' => $errors->has('reason')])
                                      placeholder="e.g. We do offer this on request"></textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="mt-2 d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">Dismiss issue</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelDismiss">Cancel</button>
                            </div>
                        </form>
                    @elseif ($issue->status === \App\Enums\IssueStatus::Open)
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="startDismiss({{ $issue->id }})">
                            <i class="fa-solid fa-xmark me-1" aria-hidden="true"></i> Dismiss
                        </button>
                    @else
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="reopen({{ $issue->id }})">
                            <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i> Reopen
                        </button>
                    @endif
                </div>
            </x-accuracy-issue>
        @empty
            <x-empty-state icon="fa-circle-check"
                           :text="$status === 'open' ? 'No open issues. AI assistants are not contradicting your facts.' : 'No issues match these filters.'" />
        @endforelse
    </x-card>

    <x-card title="Missing facts" subtitle="AI mentions these, but your facts do not cover them, so we cannot check what it says" :flush="true" :collapsible="false">
        @if ($missingFacts->isEmpty())
            <x-empty-state icon="fa-list-check" text="No missing facts right now." />
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">AI mentions</th>
                            <th scope="col">What it says</th>
                            <th scope="col" class="cv-num">Times</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($missingFacts as $fact)
                            <tr wire:key="missing-{{ $fact->id }}">
                                <td class="fw-semibold">{{ $fact->fact_key }}</td>
                                <td>{{ $fact->ai_claim }}</td>
                                <td class="cv-num">{{ $fact->occurrences }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <x-slot:footer>
            <a href="{{ route('hotels.edit', $hotel) }}#facts">Add them to your facts</a> so we can check what AI says.
        </x-slot:footer>
    </x-card>
</div>
