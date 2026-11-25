@php
    use App\Enums\Effort;
    use App\Enums\Impact;
    use App\Enums\RecommendationCategory;
    use App\Enums\RecommendationStatus;
    use App\Services\Recommendations\RecommendationReport;

    $tz = $hotel->team?->timezoneName() ?? config('citevanta.default_timezone');
@endphp

<div>
    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}</div>
    @endif

    <x-card title="Quick wins" subtitle="High impact, an hour or less" :collapsible="false" data-tour="rec-quick-wins">
        @if ($quickWins->isEmpty())
            <x-empty-state icon="fa-bolt" text="No quick wins right now. Check the full list below." />
        @else
            <div class="row gx-3">
                @foreach ($quickWins as $win)
                    <div class="col-md-6 col-xl-3 mb-2" wire:key="quick-{{ $win->id }}">
                        <a href="#recommendation-{{ $win->id }}" class="cv-quick-win">
                            <span class="small text-muted"><i class="fa-solid {{ $win->category->icon() }} me-1" aria-hidden="true"></i>{{ $win->category->label() }} · {{ $win->effort->label() }}</span>
                            <span class="fw-semibold d-block">{{ $win->title }}</span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card title="All recommendations" subtitle="{{ $openCount }} open" :flush="true" :collapsible="false" data-tour="rec-list">
        <form class="row g-2 p-3 pb-0" aria-label="Filter and sort recommendations" wire:submit.prevent>
            <div class="col-sm-6 col-lg">
                <label class="form-label small" for="rec-category">Category</label>
                <select id="rec-category" class="form-select form-select-sm" wire:model.live="category">
                    <option value="">All</option>
                    @foreach (RecommendationCategory::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg">
                <label class="form-label small" for="rec-status">Status</label>
                <select id="rec-status" class="form-select form-select-sm" wire:model.live="status">
                    <option value="open">Open</option>
                    <option value="looks_done">Looks done</option>
                    @foreach (RecommendationStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                    <option value="all">All</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg">
                <label class="form-label small" for="rec-impact">Impact</label>
                <select id="rec-impact" class="form-select form-select-sm" wire:model.live="impact">
                    <option value="">Any</option>
                    @foreach (Impact::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg">
                <label class="form-label small" for="rec-effort">Effort</label>
                <select id="rec-effort" class="form-select form-select-sm" wire:model.live="effort">
                    <option value="">Any</option>
                    @foreach (Effort::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg">
                <label class="form-label small" for="rec-sort">Sort by</label>
                <select id="rec-sort" class="form-select form-select-sm" wire:model.live="sort">
                    @foreach (RecommendationReport::SORTS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        @if ($items->isEmpty())
            <x-empty-state icon="fa-lightbulb" text="No recommendations match these filters. New ones appear after each scan." />
        @else
            <div class="accordion accordion-flush mt-3" id="recommendations">
                @php
                    // For the guided tour: the first recommendation that is open, and the first that is locked (trial).
                    $tourOpenId = $items->first(fn ($row) => $unlocked === null || in_array($row->id, $unlocked, true))?->id;
                    $tourLockedId = $items->first(fn ($row) => $unlocked !== null && ! in_array($row->id, $unlocked, true))?->id;
                @endphp
                @foreach ($items as $item)
                    @if ($unlocked !== null && ! in_array($item->id, $unlocked, true))
                        {{-- Trial: title and category only. --}}
                        <div class="accordion-item" wire:key="rec-{{ $item->id }}" id="recommendation-{{ $item->id }}" @if ($item->id === $tourLockedId) data-tour="rec-locked" @endif>
                            <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-3">
                                <span class="flex-grow-1">
                                    <span class="d-block fw-semibold">{{ $item->title }}</span>
                                    <span class="small text-muted"><i class="fa-solid {{ $item->category->icon() }} me-1" aria-hidden="true"></i>{{ $item->category->label() }}</span>
                                </span>
                                <span class="small text-muted">
                                    <i class="fa-solid fa-lock me-1" aria-hidden="true"></i>
                                    @if ($canBill)
                                        <a href="{{ route('settings.billing') }}">{{ \App\Services\Trials\TrialLocks::message($openCount) }}</a>
                                    @else
                                        {{ \App\Services\Trials\TrialLocks::message($openCount) }}
                                    @endif
                                </span>
                            </div>
                        </div>
                        @continue
                    @endif
                    <div class="accordion-item" wire:key="rec-{{ $item->id }}" id="recommendation-{{ $item->id }}" @if ($item->id === $tourOpenId) data-tour="rec-first" @endif>
                        <h3 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#rec-body-{{ $item->id }}" aria-expanded="false" aria-controls="rec-body-{{ $item->id }}">
                                <span class="flex-grow-1">
                                    <span class="d-block fw-semibold">{{ $item->title }}</span>
                                    <span class="small text-muted"><i class="fa-solid {{ $item->category->icon() }} me-1" aria-hidden="true"></i>{{ $item->category->label() }} · {{ $item->effort->label() }}</span>
                                </span>
                                <span class="d-flex flex-wrap gap-1 me-2 justify-content-end">
                                    @if ($item->looks_done_at && $item->status->isOpen())
                                        <x-badge-status label="Looks done" variant="success" icon="fa-circle-check" />
                                    @endif
                                    <x-badge-status :label="$item->impact->label().' impact'" :variant="$item->impact->variant()" />
                                    <x-badge-status :label="$item->status->label()" :variant="$item->status->variant()" />
                                </span>
                            </button>
                        </h3>
                        <div id="rec-body-{{ $item->id }}" class="accordion-collapse collapse" wire:ignore.self>
                            <div class="accordion-body">
                                @if ($item->looks_done_at && $item->status->isOpen())
                                    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 py-2" role="status">
                                        <span>The evidence for this is gone in the latest scan. Did you do it?</span>
                                        <button type="button" class="btn btn-sm btn-success" wire:click="confirmDone({{ $item->id }})">Yes, mark done</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="notDoneYet({{ $item->id }})">Not yet</button>
                                    </div>
                                @endif

                                <p class="mb-2"><strong>Why:</strong> {{ $item->why }}</p>

                                <p class="fw-semibold mb-1">What to do</p>
                                <ol class="mb-2">
                                    @foreach ($item->steps as $step)
                                        <li>{{ $step }}</li>
                                    @endforeach
                                </ol>
                                <p class="small mb-2"><strong>Where:</strong> {{ $item->where }} · <strong>Effort:</strong> {{ $item->effort->label() }} · <strong>Priority:</strong> {{ $item->priority }}</p>

                                @if ($item->evidence)
                                    <p class="small fw-semibold mb-1">Evidence</p>
                                    <ul class="small mb-2">
                                        @foreach ($item->evidence as $evidence)
                                            <li>
                                                @if (! empty($evidence['url']))
                                                    <a href="{{ $evidence['url'] }}">{{ $evidence['label'] }}</a>
                                                @else
                                                    {{ $evidence['label'] }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($impact = $impacts[$item->id] ?? null)
                                    <div class="cv-measured mb-2">
                                        <p class="small fw-semibold mb-1">Measured impact</p>
                                        @if ($impact['change'] !== null)
                                            <p class="small mb-0">
                                                {{ $impact['label'] }}: {{ \App\Services\Dashboard\DashboardService::pct($impact['before']) }}
                                                → {{ \App\Services\Dashboard\DashboardService::pct($impact['after']) }}
                                                <span @class(['fw-semibold', 'text-success' => $impact['change'] > 0, 'text-danger' => $impact['change'] < 0])>
                                                    ({{ $impact['change'] > 0 ? '+' : '' }}{{ $impact['change'] }} pts)
                                                </span>
                                            </p>
                                        @endif
                                        @if ($impact['note'])
                                            <p class="small text-muted mb-0">{{ $impact['note'] }}</p>
                                        @endif
                                    </div>
                                @endif

                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2" role="group" aria-label="Status">
                                    @foreach ([RecommendationStatus::ToDo, RecommendationStatus::InProgress, RecommendationStatus::Done] as $option)
                                        <button type="button" wire:click="setStatus({{ $item->id }}, '{{ $option->value }}')"
                                                @class(['btn btn-sm', 'btn-primary' => $item->status === $option, 'btn-outline-primary' => $item->status !== $option])
                                                aria-pressed="{{ $item->status === $option ? 'true' : 'false' }}">{{ $option->label() }}</button>
                                    @endforeach
                                    @if ($item->status !== RecommendationStatus::Dismissed)
                                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="startDismiss({{ $item->id }})">Dismiss</button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-md-auto" wire:click="openContent({{ $item->id }})"
                                            aria-expanded="{{ $contentFor === $item->id ? 'true' : 'false' }}">
                                        <i class="fa-solid fa-wand-magic-sparkles me-1" aria-hidden="true"></i> Create content
                                    </button>
                                </div>

                                @if ($item->status_changed_at)
                                    <p class="small text-muted mb-2">
                                        {{ $item->status->label() }} · changed by {{ $item->statusChangedBy?->name ?? 'a team member' }}
                                        on {{ $item->status_changed_at->copy()->setTimezone($tz)->format('j M Y, H:i') }}
                                        @if ($item->dismissed_reason) · “{{ $item->dismissed_reason }}” @endif
                                    </p>
                                @endif

                                @if ($dismissing === $item->id)
                                    <form wire:submit="dismiss" class="cv-issue__dismiss mb-2">
                                        <label class="form-label small" for="rec-dismiss-{{ $item->id }}">Why are you dismissing this?</label>
                                        <textarea id="rec-dismiss-{{ $item->id }}" rows="2" maxlength="500" wire:model="reason"
                                                  @class(['form-control form-control-sm', 'is-invalid' => $errors->has('reason')])></textarea>
                                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="mt-2 d-flex gap-2">
                                            <button type="submit" class="btn btn-sm btn-primary">Dismiss</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelDismiss">Cancel</button>
                                        </div>
                                    </form>
                                @endif

                                @if ($contentFor === $item->id)
                                    <livewire:hotels.content-panel :recommendation-id="$item->id" :wire:key="'content-'.$item->id" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>
</div>
