<div>
    @php($full = $used >= $limit)

    <x-card title="Competitors" subtitle="Hotels you compete with for the same guests. Changes save automatically." :collapsible="false" :flush="true">
        <x-slot:actions>
            <span @class(['cv-usage', 'is-full' => $full])>{{ $used }} of {{ $limit }} used</span>
        </x-slot:actions>

        @if (! empty($stats['self']))
            <p class="small px-3 pt-3 mb-0">
                <span class="fw-semibold">Your hotel in the latest scan:</span>
                visibility {{ \App\Services\Dashboard\DashboardService::pct($stats['self']['visibility']) }},
                share of voice {{ \App\Services\Dashboard\DashboardService::pct($stats['self']['share']) }}.
            </p>
        @endif

        <div class="table-responsive">
            <table class="table cv-edit-table mb-0">
                <thead>
                    <tr>
                        <th scope="col" style="width: 45%">Name</th>
                        <th scope="col">Website <span class="text-muted fw-normal">(optional)</span></th>
                        @if ($stats !== null)
                            <th scope="col" class="cv-num">Visibility</th>
                            <th scope="col" class="cv-num">Share of voice</th>
                        @endif
                        <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $id => $row)
                        <tr wire:key="competitor-{{ $id }}">
                            <td>
                                <label for="competitor-name-{{ $id }}" class="visually-hidden">Competitor name</label>
                                <input id="competitor-name-{{ $id }}" type="text" wire:model.blur="rows.{{ $id }}.name"
                                       @class(['form-control', 'is-invalid' => $errors->has("rows.$id.name")])>
                                @error("rows.$id.name")<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <label for="competitor-website-{{ $id }}" class="visually-hidden">Website of {{ $row['name'] }}</label>
                                <input id="competitor-website-{{ $id }}" type="url" wire:model.blur="rows.{{ $id }}.website" placeholder="https://"
                                       @class(['form-control', 'is-invalid' => $errors->has("rows.$id.website")])>
                                @error("rows.$id.website")<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            @if ($stats !== null)
                                <td class="cv-num">{{ isset($stats[$id]) ? \App\Services\Dashboard\DashboardService::pct($stats[$id]['visibility']) : '—' }}</td>
                                <td class="cv-num">{{ isset($stats[$id]) ? \App\Services\Dashboard\DashboardService::pct($stats[$id]['share']) : '—' }}</td>
                            @endif
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="remove({{ $id }})"
                                        wire:confirm="Remove {{ $row['name'] }} from your competitors?" aria-label="Remove {{ $row['name'] }}">
                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $stats !== null ? 5 : 3 }}">
                                <x-empty-state icon="fa-chess-knight" text="No competitors yet. Add the hotels travellers compare you with." class="py-3" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form wire:submit="add" class="px-3 py-3 border-top" novalidate>
            <div class="row g-2 align-items-start">
                <div class="col-md-5">
                    <label for="new-competitor-name" class="form-label">Add competitor</label>
                    <input id="new-competitor-name" type="text" wire:model="newName" placeholder="Competitor hotel name" @disabled($full)
                           @class(['form-control', 'is-invalid' => $errors->has('newName')])>
                    @error('newName')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label for="new-competitor-website" class="form-label">Website</label>
                    <input id="new-competitor-website" type="url" wire:model="newWebsite" placeholder="https://" @disabled($full)
                           @class(['form-control', 'is-invalid' => $errors->has('newWebsite')])>
                    @error('newWebsite')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2 d-grid">
                    <label class="form-label d-none d-md-block" aria-hidden="true">&nbsp;</label>
                    <button type="submit" class="btn btn-outline-primary" @disabled($full)>
                        <i class="fa-solid fa-plus me-1" aria-hidden="true"></i> Add
                    </button>
                </div>
            </div>
            @if ($full)
                <p class="small text-muted mt-2 mb-0">You have used all {{ $limit }} competitor slots on your plan. Remove one to add another.</p>
            @endif
        </form>

        @if ($wizard)
            <x-slot:footer>
                <div class="d-flex justify-content-between gap-2">
                    <button type="button" class="btn btn-outline-secondary" wire:click="back">
                        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i> Back
                    </button>
                    <button type="button" class="btn btn-primary" wire:click="continue">
                        Save and continue <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                    </button>
                </div>
            </x-slot:footer>
        @endif
    </x-card>
</div>
