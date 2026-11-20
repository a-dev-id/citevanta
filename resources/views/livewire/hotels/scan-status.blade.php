<div @if ($scan) wire:poll.10s @endif>
    @if ($scan)
        <div class="cv-scan-banner" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span>
            <div class="flex-grow-1">
                @if ($scan->status === \App\Enums\ScanStatus::Waiting)
                    <strong>{{ $scan->hold_reason === \App\Services\Trials\TrialGate::APPROVAL ? 'We are preparing your account' : 'Verify your email to start' }}</strong>
                    · {{ \App\Services\Trials\TrialGate::message($scan->hold_reason) }}
                @elseif ($scan->status === \App\Enums\ScanStatus::PausedBudget)
                    <strong>Scan paused</strong> · we are processing many scans right now. It continues where it stopped as soon as possible; no answers are lost.
                @elseif ($scan->status === \App\Enums\ScanStatus::Running && $progress->planned > 0)
                    <strong>Scan running</strong> · {{ $progress->answers }} of {{ $progress->planned }} answers collected
                    @if ($progress->failed > 0)
                        <span class="text-muted">({{ $progress->failed }} failed)</span>
                    @endif
                    @if ($progress->minutesLeft !== null)
                        · about {{ $progress->minutesLeft }} {{ Str::plural('minute', $progress->minutesLeft) }} left
                    @endif
                    <div class="progress mt-2" role="progressbar" aria-label="Scan progress"
                         aria-valuenow="{{ $progress->answers }}" aria-valuemin="0" aria-valuemax="{{ $progress->planned }}">
                        <div class="progress-bar" style="width: {{ round(($progress->answers + $progress->failed) / $progress->planned * 100) }}%"></div>
                    </div>
                @else
                    <strong>Scan queued</strong> · it starts with the next background run (usually within a minute).
                @endif
                <span class="d-block small text-muted mt-1">
                    Running in the background: you can use other pages or close this one. Until it finishes, the results below are from the last completed scan.
                </span>
            </div>
        </div>
    @endif
</div>
