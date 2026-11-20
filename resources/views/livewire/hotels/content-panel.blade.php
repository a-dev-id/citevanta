<div class="cv-content-panel" @if ($piece?->status === \App\Enums\ContentStatus::Pending) wire:poll.4s @endif>
    <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
        <div>
            <label class="form-label small mb-1" for="content-type-{{ $recommendation->id }}">Content</label>
            <select id="content-type-{{ $recommendation->id }}" class="form-select form-select-sm" wire:model.live="type">
                <optgroup label="Suggested">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Other">
                    @foreach ($allTypes as $option)
                        @unless (in_array($option, $types, true))
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endunless
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div>
            <label class="form-label small mb-1" for="content-language-{{ $recommendation->id }}">Language</label>
            <select id="content-language-{{ $recommendation->id }}" class="form-select form-select-sm" wire:model.live="language">
                @foreach ($languages as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" class="btn btn-sm btn-primary" wire:click="generate" wire:loading.attr="disabled" wire:target="generate"
                @disabled(! $allowance['unlimited'] && $allowance['left'] === 0)>
            <span wire:loading wire:target="generate" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
            <i class="fa-solid fa-wand-magic-sparkles me-1" aria-hidden="true" wire:loading.remove wire:target="generate"></i>
            {{ $versions->isEmpty() ? 'Create content' : 'Create a new version' }}
        </button>
        <span class="small text-muted ms-auto">
            @if ($allowance['unlimited'])
                Unlimited (admin)
            @else
                {{ $allowance['left'] }} of {{ $allowance['limit'] }} left this month
            @endif
        </span>
    </div>

    @if ($error)
        <div class="alert alert-warning py-2 small" role="alert">{{ $error }}</div>
    @endif
    @if ($notice)
        <div class="alert alert-success py-2 small" role="status">{{ $notice }}</div>
    @endif

    @if ($piece)
        @if ($versions->count() > 1)
            <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Versions">
                @foreach ($versions as $version)
                    <button type="button" wire:click="show({{ $version->id }})"
                            @class(['btn btn-sm', 'btn-primary' => $version->id === $piece->id, 'btn-outline-secondary' => $version->id !== $piece->id])>
                        v{{ $version->version }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($piece->status === \App\Enums\ContentStatus::Pending)
            <p class="small text-muted mb-0" role="status">
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                Writing {{ mb_strtolower($piece->type->label()) }}… This usually takes under a minute.
            </p>
        @elseif ($piece->status === \App\Enums\ContentStatus::Failed)
            <div class="alert alert-warning py-2 small mb-0" role="alert">This version could not be created: {{ $piece->error }}. Try again.</div>
        @else
            <p class="small mb-1"><strong>Where to paste:</strong> {{ $piece->type->whereToPaste() }}</p>
            @if (str_contains($piece->text(), '[add:') || str_contains($piece->text(), '[check:'))
                <p class="small text-warning-emphasis mb-1">
                    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                    Replace the [add: …] placeholders with real details (or add those facts and create a new version), and check anything marked [check: …].
                </p>
            @endif
            <label class="visually-hidden" for="content-text-{{ $piece->id }}">Content text</label>
            <textarea id="content-text-{{ $piece->id }}" rows="12" wire:model="text"
                      @class(['form-control form-control-sm cv-content-text', 'font-monospace' => $piece->type === \App\Enums\ContentType::SchemaJsonLd])></textarea>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-cv-copy="#content-text-{{ $piece->id }}">
                    <i class="fa-regular fa-copy me-1" aria-hidden="true"></i> <span>Copy</span>
                </button>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('hotels.content.download', [$recommendation->hotel, $piece]) }}">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i> Download
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="saveEdit">Save edit</button>
                <span class="small text-muted">
                    v{{ $piece->version }} · {{ $piece->created_at->diffForHumans() }}@if ($piece->creator) by {{ $piece->creator->name }}@endif
                    @if ($piece->edited_body) · edited @endif
                </span>
                @foreach ($limits as $limit)
                    <span class="small text-muted">{{ $limit['label'] }}: max {{ number_format($limit['max']) }} {{ $limit['unit'] }}</span>
                @endforeach
                <span class="small text-muted ms-auto">{{ number_format(mb_strlen($text)) }} characters · {{ number_format(str_word_count($text)) }} words (whole text)</span>
            </div>
        @endif
    @endif
</div>
