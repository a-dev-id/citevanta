@php($tz = $team->timezoneName())
<x-layouts::app title="AI key" :breadcrumbs="['Settings' => null, 'AI key' => null]">
    <x-flash />

    @if ($stopMessage)
        <div class="alert alert-danger" role="alert">
            <p class="fw-semibold mb-1"><i class="fa-solid fa-circle-stop me-1" aria-hidden="true"></i> Your scans and content are paused.</p>
            {{ $stopMessage }}
        </div>
    @endif

    <div class="row gx-3">
        <div class="col-xl-7">
            <x-card title="Your OpenAI API key" subtitle="On the Private plan every AI call of your team (scans, analysis, insights, content) uses your own key." :collapsible="false">
                @if ($team->hasOwnAiKey())
                    <p class="mb-1">Saved key: <code>{{ $team->maskedAiKey() }}</code></p>
                    <p class="small text-muted">
                        Last checked {{ $team->openai_key_checked_at?->setTimezone($tz)->format('j M Y, H:i') ?? 'never' }}.
                        For your security the full key is never shown again.
                    </p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <form method="POST" action="{{ route('settings.ai-key.test') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">Test again</button>
                        </form>
                        <form method="POST" action="{{ route('settings.ai-key.remove') }}" onsubmit="return confirm('Remove the key? Scans and content stop until you add one again.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove key</button>
                        </form>
                    </div>
                @else
                    <div class="alert alert-info small" role="status">
                        No key yet. Scans and content start as soon as a working key is saved.
                        <a href="{{ route('settings.ai-key.guide') }}">How to create one</a>.
                    </div>
                @endif

                <form method="POST" action="{{ route('settings.ai-key.save') }}" novalidate autocomplete="off">
                    @csrf
                    <label for="key" class="form-label">{{ $team->hasOwnAiKey() ? 'Replace with a new key' : 'Paste your key' }}</label>
                    <input id="key" name="key" type="password" autocomplete="off" spellcheck="false" placeholder="sk-..."
                           @class(['form-control', 'is-invalid' => $errors->has('key')]) aria-describedby="key-help">
                    @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div id="key-help" class="form-text">We test it with one free request (list models) before saving. It is stored encrypted.</div>
                    <button type="submit" class="btn btn-primary mt-2">Test and save</button>
                </form>
            </x-card>
        </div>

        <div class="col-xl-5">
            <x-card title="Monthly AI budget" subtitle="A safety limit for your own key, on top of the spend limit in OpenAI" :collapsible="false">
                <p class="mb-2">This month: <strong class="cv-num">${{ number_format($spent, 2) }}</strong> of <span class="cv-num">${{ number_format($team->ai_budget_usd, 2) }}</span></p>
                <div class="progress mb-3" role="progressbar" aria-label="AI budget used"
                     aria-valuenow="{{ $team->ai_budget_usd > 0 ? min(100, round($spent / $team->ai_budget_usd * 100)) : 100 }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: {{ $team->ai_budget_usd > 0 ? min(100, round($spent / $team->ai_budget_usd * 100)) : 100 }}%"></div>
                </div>
                <form method="POST" action="{{ route('settings.ai-key.budget') }}" class="d-flex flex-wrap align-items-end gap-2" novalidate>
                    @csrf
                    @method('PUT')
                    <div>
                        <label for="ai_budget_usd" class="form-label">Monthly limit (USD)</label>
                        <input id="ai_budget_usd" name="ai_budget_usd" type="number" min="1" step="1" value="{{ old('ai_budget_usd', (int) $team->ai_budget_usd) }}"
                               @class(['form-control', 'is-invalid' => $errors->has('ai_budget_usd')]) style="max-width: 10rem">
                        @error('ai_budget_usd')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Save limit</button>
                </form>
                <p class="small text-muted mt-2 mb-0">We email you at 50%, 80% and 100%. At 100% scans and content pause until the 1st (Asia/Makassar) or until you raise it.</p>
            </x-card>
        </div>
    </div>
</x-layouts::app>
