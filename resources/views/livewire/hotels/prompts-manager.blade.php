<div>
    @php($full = $activeCount >= $limit)

    @if ($notice)
        <div class="alert alert-success" role="status">
            <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> {{ $notice }}
        </div>
    @endif

    <x-card title="Prompts" subtitle="Questions travellers ask AI assistants. They never include your hotel's name. Changes save automatically." :collapsible="false" :flush="true">
        <x-slot:actions>
            <span @class(['cv-usage me-2', 'is-full' => $full])>{{ $activeCount }} of {{ $limit }} active</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="regenerate"
                    wire:confirm="Replace the suggested prompts you have not edited? Your edited and custom prompts are kept.">
                <i class="fa-solid fa-rotate me-1" aria-hidden="true" wire:loading.class="fa-spin" wire:target="regenerate"></i> Regenerate suggestions
            </button>
        </x-slot:actions>

        <div class="cv-language-bar d-flex flex-wrap align-items-end gap-2 px-3 py-2">
            <div class="small me-auto">
                <span class="fw-semibold">Languages:</span>
                {{ collect($usedLanguages)->map(fn ($code) => config("citevanta.languages.{$code}", $code))->implode(', ') ?: 'none yet' }}
                <span class="text-muted">({{ count($usedLanguages) }} of {{ $languageLimit }} on your plan)</span>
                @if (count($suggestedLanguages) > 1)
                    <span class="d-block text-muted">Suggested from your guest markets: {{ implode(', ', $suggestedLanguages) }}</span>
                @endif
            </div>
            <div>
                <label class="form-label small mb-1" for="generate-language">Add prompts in</label>
                <select id="generate-language" wire:model="generateLanguage"
                        @class(['form-select form-select-sm', 'is-invalid' => $errors->has('generateLanguage')])>
                    <option value="">Choose a language</option>
                    @foreach ($generatable as $code => $name)
                        <option value="{{ $code }}">{{ $name }}@if (array_key_exists($code, $suggestedLanguages)) (suggested)@endif</option>
                    @endforeach
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addLanguage" wire:loading.attr="disabled" wire:target="addLanguage">
                <i class="fa-solid fa-language me-1" aria-hidden="true"></i> Add
            </button>
            @error('generateLanguage')<div class="invalid-feedback d-block w-100 text-end">{{ $message }}</div>@enderror
        </div>

        <div class="table-responsive">
            <table class="table cv-edit-table mb-0">
                <thead>
                    <tr>
                        <th scope="col">Prompt</th>
                        <th scope="col" style="width: 150px">Persona</th>
                        <th scope="col" style="width: 140px">Language</th>
                        <th scope="col" style="width: 80px">Active</th>
                        @if ($visibility !== null)
                            <th scope="col" class="cv-num" style="width: 110px">Latest visibility</th>
                        @endif
                        <th scope="col" class="text-end" style="width: 60px"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                @forelse ($groups as $persona => $rowsInGroup)
                    <tbody>
                        <tr class="cv-group-row">
                            <th colspan="{{ $visibility !== null ? 6 : 5 }}" scope="colgroup">{{ ucfirst($persona) }} <span class="fw-normal">({{ count($rowsInGroup) }})</span></th>
                        </tr>
                        @foreach ($rowsInGroup as $id => $row)
                            @php($model = $models[$id] ?? null)
                            <tr wire:key="prompt-{{ $id }}">
                                <td>
                                    <label for="prompt-text-{{ $id }}" class="visually-hidden">Prompt text</label>
                                    <textarea id="prompt-text-{{ $id }}" rows="2" wire:model.blur="rows.{{ $id }}.text"
                                              @class(['form-control', 'is-invalid' => $errors->has("rows.$id.text")])></textarea>
                                    @error("rows.$id.text")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    @if ($model)
                                        <div class="mt-1 d-flex gap-1">
                                            <x-badge-status :label="$model->source->value === 'generated' ? 'Suggested' : 'Custom'"
                                                            :variant="$model->source->value === 'generated' ? 'info' : 'primary'" />
                                            @if ($model->is_edited)
                                                <x-badge-status label="Edited" variant="neutral" icon="fa-pen" />
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <label for="prompt-persona-{{ $id }}" class="visually-hidden">Persona</label>
                                    <select id="prompt-persona-{{ $id }}" wire:model.live="rows.{{ $id }}.persona"
                                            @class(['form-select', 'is-invalid' => $errors->has("rows.$id.persona")])>
                                        @foreach ($personas as $option)
                                            <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <label for="prompt-language-{{ $id }}" class="visually-hidden">Language</label>
                                    <select id="prompt-language-{{ $id }}" wire:model.live="rows.{{ $id }}.language"
                                            @class(['form-select', 'is-invalid' => $errors->has("rows.$id.language")])>
                                        @foreach ($languagesFor($model) as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error("rows.$id.language")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="prompt-active-{{ $id }}"
                                               wire:model.live="rows.{{ $id }}.is_active">
                                        <label class="form-check-label visually-hidden" for="prompt-active-{{ $id }}">Active</label>
                                    </div>
                                    @error("rows.$id.is_active")<div class="text-danger small">{{ $message }}</div>@enderror
                                </td>
                                @if ($visibility !== null)
                                    <td class="cv-num">{{ array_key_exists($id, $visibility) ? \App\Services\Dashboard\DashboardService::pct($visibility[$id]) : '—' }}</td>
                                @endif
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-1" wire:click="remove({{ $id }})"
                                            wire:confirm="Delete this prompt?" aria-label="Delete prompt">
                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="{{ $visibility !== null ? 6 : 5 }}">
                                <x-empty-state icon="fa-comments" text="No prompts yet. Add your own or regenerate suggestions." class="py-3" />
                            </td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        <form wire:submit="add" class="px-3 py-3 border-top" novalidate>
            <div class="row g-2 align-items-start">
                <div class="col-lg-6">
                    <label for="new-prompt-text" class="form-label">Add your own prompt</label>
                    <input id="new-prompt-text" type="text" wire:model="newText" placeholder="e.g. Which resort near Ubud has the best yoga classes?"
                           @class(['form-control', 'is-invalid' => $errors->has('newText')])>
                    @error('newText')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-lg-2">
                    <label for="new-prompt-persona" class="form-label">Persona</label>
                    <select id="new-prompt-persona" wire:model="newPersona" class="form-select">
                        @foreach ($personas as $option)
                            <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label for="new-prompt-language" class="form-label">Language</label>
                    <select id="new-prompt-language" wire:model="newLanguage" @class(['form-select', 'is-invalid' => $errors->has('newLanguage')])>
                        @foreach ($languagesFor(null) as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('newLanguage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-lg-2 d-grid">
                    <label class="form-label d-none d-lg-block" aria-hidden="true">&nbsp;</label>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add prompt
                    </button>
                </div>
            </div>
        </form>

        @if ($wizard)
            <x-slot:footer>
                <div class="d-flex justify-content-between gap-2">
                    <button type="button" class="btn btn-outline-secondary" wire:click="back">
                        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back
                    </button>
                    <button type="button" class="btn btn-success" wire:click="finish" wire:loading.attr="disabled">
                        <i class="fa-solid fa-flag-checkered me-1" aria-hidden="true"></i> Finish setup
                    </button>
                </div>
            </x-slot:footer>
        @endif
    </x-card>
</div>
