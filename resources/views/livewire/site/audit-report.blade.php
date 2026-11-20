<div @unless ($finished) wire:poll.5s @endunless>
    <section class="cv-section">
        <div class="container" style="max-width: 960px">
            <span class="cv-eyebrow">{{ __('site.audit_page.title') }}</span>
            <h1 class="cv-section-title">{{ $audit->hotel_name }}, {{ $audit->city }}</h1>

            @if (! $finished)
                <div class="cv-tile mt-4" role="status" aria-live="polite">
                    <h2 class="h4">{{ __('audit.report.running_title') }}</h2>
                    <p>{{ __('audit.report.running_text', ['city' => $audit->city, 'email' => $audit->email]) }}</p>
                    <div class="progress mb-2" role="progressbar" aria-label="{{ __('audit.report.running_title') }}"
                         aria-valuenow="{{ $answered }}" aria-valuemin="0" aria-valuemax="{{ $total }}" style="height: 10px">
                        <div class="progress-bar" style="width: {{ $total ? round($answered / $total * 100) : 0 }}%"></div>
                    </div>
                    <p class="small text-muted mb-0">{{ __('audit.report.progress', ['done' => $answered, 'total' => $total]) }}</p>
                </div>
            @elseif ($results->status === \App\Models\Audit::FAILED)
                <div class="cv-tile mt-4" role="alert">
                    <h2 class="h4">{{ __('audit.report.failed_title') }}</h2>
                    <p class="mb-0">{{ __('audit.report.failed_text') }}</p>
                </div>
            @else
                @php
                    $score = (float) $results->visibility;
                    $scoreClass = $score >= 50 ? 'is-high' : ($score >= 20 ? 'is-mid' : 'is-low');
                @endphp
                <div class="row g-4 align-items-center mt-1">
                    <div class="col-md-4 text-center">
                        <div class="cv-score {{ $scoreClass }}" aria-label="{{ __('audit.report.score_label') }}: {{ round($score) }}%">{{ round($score) }}%</div>
                        <p class="fw-semibold mt-2 mb-0">{{ __('audit.report.score_label') }}</p>
                        <p class="small text-muted">{{ __('audit.report.score_text', ['count' => $results->questions_mentioned, 'total' => $results->questions]) }}</p>
                    </div>
                    <div class="col-md-8">
                        <h2 class="h4">{{ __('audit.report.insights_title') }}</h2>
                        <ul class="mb-0">
                            @foreach ($results->insights ?? [] as $insight)
                                @php($params = $insight['params'] + (isset($insight['params']['type']) ? ['type' => __('audit.types.'.$insight['params']['type'])] : []))
                                <li class="mb-1">{{ __('audit.insights.'.$insight['key'], $params) }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="row g-4 mt-2">
                    <div class="col-md-6">
                        <div class="cv-tile">
                            <h2 class="h5">{{ __('audit.report.instead_title') }}</h2>
                            @if (empty($results->recommended_instead))
                                <p class="mb-0">{{ __('audit.report.instead_none') }}</p>
                            @else
                                <ol class="mb-0">
                                    @foreach ($results->recommended_instead as $hotel)
                                        <li class="mb-1"><span class="fw-semibold">{{ $hotel['name'] }}</span>
                                            <span class="text-muted small">· {{ trans_choice('audit.report.answers', $hotel['answers'], ['count' => $hotel['answers']]) }}</span></li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="cv-tile">
                            <h2 class="h5">{{ __('audit.report.sources_title') }}</h2>
                            @if ($domainTypes === [])
                                <p class="mb-0">{{ __('audit.report.sources_none') }}</p>
                            @else
                                @foreach ($domainTypes as $row)
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="flex: 0 0 45%">{{ ucfirst(__('audit.types.'.$row['type']->value)) }}</span>
                                        <div class="progress flex-grow-1" style="height: 8px" aria-hidden="true">
                                            <div class="progress-bar" style="width: {{ $row['share'] }}%"></div>
                                        </div>
                                        <span class="small fw-semibold" style="width: 44px; text-align: right">{{ $row['share'] }}%</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>

                @if ($questionList !== [])
                    <details class="mt-4">
                        <summary class="fw-semibold">{{ __('audit.report.questions_title') }}</summary>
                        <ol class="mt-2">
                            @foreach ($questionList as $question)
                                <li>{{ $question }}</li>
                            @endforeach
                        </ol>
                    </details>
                @endif

                <p class="small text-muted mt-3">{{ __('audit.report.note', ['date' => $results->finished_at?->format('j M Y')]) }}</p>
            @endif
        </div>
    </section>

    <section class="cv-section cv-section--dark text-center">
        <div class="container" style="max-width: 760px">
            <h2 class="cv-section-title">{{ __('audit.report.cta_title') }}</h2>
            <p class="cv-section-lead mx-auto">{{ __('audit.report.cta_text') }}</p>
            <a href="{{ route('register', ['audit' => $audit->token]) }}" class="btn btn-primary">{{ __('audit.report.cta', ['days' => $trialDays]) }}</a>
        </div>
    </section>
</div>
