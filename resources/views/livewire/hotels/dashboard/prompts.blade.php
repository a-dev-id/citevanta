<x-card title="Prompt results" subtitle="Expand a prompt to read every answer from this scan" :flush="true">
    @if ($d->prompts === [])
        <x-empty-state icon="fa-comments" text="No answers in this scan." />
    @else
        <div class="accordion accordion-flush" id="prompt-results">
            @foreach ($d->prompts as $prompt)
                <div class="accordion-item" wire:key="prompt-result-{{ $prompt['id'] }}">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                data-bs-target="#prompt-result-{{ $prompt['id'] }}" aria-expanded="false"
                                aria-controls="prompt-result-{{ $prompt['id'] }}">
                            <span class="flex-grow-1">{{ $prompt['text'] }}</span>
                            <x-badge-status :label="ucfirst($prompt['persona'])" variant="primary" />
                            <strong class="ms-2 me-2 text-nowrap" title="{{ $prompt['mentions'] }} of {{ $prompt['answers'] }} answers">
                                {{ \App\Services\Dashboard\DashboardService::pct($prompt['visibility']) }}
                            </strong>
                        </button>
                    </h3>
                    <div id="prompt-result-{{ $prompt['id'] }}" class="accordion-collapse collapse" wire:ignore.self>
                        <div class="accordion-body">
                            @if ($prompt['why'] ?? null)
                                @include('livewire.hotels.dashboard.why-competitors', ['why' => $prompt['why']])
                            @endif
                            @foreach ($prompt['responses'] as $response)
                                <div class="mb-3 cv-answer-block" id="answer-{{ $response['id'] }}">
                                    <p class="small text-muted mb-1 d-flex flex-wrap gap-2 align-items-center">
                                        <span>{{ \App\Services\Dashboard\DashboardService::engineLabel($response['engine']) }} · run {{ $response['run'] }}</span>
                                        @if ($response['sentiment'])
                                            <x-badge-status :status="$response['sentiment']" />
                                        @endif
                                        @if ($response['position'])
                                            <span>ranked #{{ $response['position'] }}</span>
                                        @endif
                                    </p>
                                    <div class="cv-answer mb-2">{{ $response['html'] }}</div>
                                    @if ($response['citations'])
                                        <p class="small fw-semibold mb-1">Citations</p>
                                        <ul class="small mb-0">
                                            @foreach ($response['citations'] as $citation)
                                                <li>
                                                    <a href="{{ $citation['url'] }}" target="_blank" rel="noopener noreferrer nofollow">
                                                        {{ $citation['title'] ?: $citation['domain'] }}
                                                        <i class="fa-solid fa-arrow-up-right-from-square fa-xs" aria-hidden="true"></i>
                                                        <span class="visually-hidden">(opens in a new tab)</span>
                                                    </a>
                                                    <span class="text-muted">{{ $citation['domain'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-card>
