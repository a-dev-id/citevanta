<div>
    @if ($saved)
        <div class="alert alert-success" role="status">
            <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i> Hotel facts saved.
        </div>
    @endif

    <x-card title="Hotel facts" subtitle="AI assistants sometimes get these details wrong. We use them to check accuracy." :collapsible="false" :flush="true">
        <form id="facts-form-{{ $this->getId() }}" wire:submit="save" novalidate>
            <div class="table-responsive">
                <table class="table cv-edit-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 35%">Fact</th>
                            <th scope="col">Value</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($facts as $index => $fact)
                            <tr wire:key="fact-{{ $index }}">
                                <td>
                                    <label for="fact-key-{{ $index }}" class="visually-hidden">Fact name</label>
                                    <input id="fact-key-{{ $index }}" type="text" wire:model="facts.{{ $index }}.key" placeholder="e.g. Beach access"
                                           @class(['form-control', 'is-invalid' => $errors->has("facts.$index.key")])>
                                    @error("facts.$index.key")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td>
                                    <label for="fact-value-{{ $index }}" class="visually-hidden">Value for {{ $fact['key'] ?: 'this fact' }}</label>
                                    <input id="fact-value-{{ $index }}" type="text" wire:model="facts.{{ $index }}.value" placeholder="Leave empty to skip"
                                           @class(['form-control', 'is-invalid' => $errors->has("facts.$index.value")])>
                                    @error("facts.$index.value")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="removeRow({{ $index }})"
                                            aria-label="Remove {{ $fact['key'] ?: 'this row' }}">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-3 d-flex flex-wrap align-items-center gap-3">
                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addRow">
                    <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add fact
                </button>
                <span class="text-muted small">Facts without a value are not saved.</span>
            </div>
        </form>

        <x-slot:footer>
            <div class="d-flex justify-content-between gap-2">
                @if ($wizard)
                    <button type="button" class="btn btn-outline-secondary" wire:click="back">
                        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back
                    </button>
                @else
                    <span></span>
                @endif
                <button type="submit" form="facts-form-{{ $this->getId() }}" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ $wizard ? 'Save and continue' : 'Save facts' }}
                    @if ($wizard)<i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>@endif
                </button>
            </div>
        </x-slot:footer>
    </x-card>
</div>
