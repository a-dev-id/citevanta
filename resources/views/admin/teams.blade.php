@php($money = fn (?array $mrr) => $mrr ? \App\Services\Billing\Money::format($mrr['amount'], $mrr['currency']) : '—')
<x-layouts::app title="Teams" :breadcrumbs="['Admin' => null, 'Teams' => null]">
    <x-flash />
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="row gx-3">
        <div class="col-md-4">
            <x-card title="Total MRR" :collapsible="false">
                @forelse ($totalMrr as $currency => $amount)
                    <p class="h4 mb-1 cv-num">{{ \App\Services\Billing\Money::format($amount, $currency) }}</p>
                @empty
                    <p class="h4 mb-1">—</p>
                @endforelse
                <p class="small text-muted mb-0">Active and past-due paid teams, annual plans divided by 12. Free (comped) plans not counted.</p>
            </x-card>
        </div>
    </div>

    <x-card title="All teams" :flush="true" :collapsible="false">
        @if ($teams->isEmpty())
            <x-empty-state icon="fa-people-group" text="No teams yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Team</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Paid until</th>
                            <th scope="col" class="cv-num">MRR</th>
                            <th scope="col" class="cv-num">Members</th><th scope="col" class="cv-num">Hotels</th>
                            <th scope="col" class="cv-num">Content <span class="d-block small fw-normal text-muted">this month</span></th>
                            <th scope="col">Created</th><th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teams as $team)
                            <tr>
                                <td class="fw-semibold">{{ $team->name }}</td>
                                <td>
                                    {{ $team->plan->label() }}@if ($team->is_comped) <span class="small text-muted">(free)</span>@endif
                                    @if ($team->billing_interval)<span class="d-block small text-muted">{{ $team->billing_interval->label() }}, {{ $team->hotel_quantity }} {{ Str::plural('hotel', $team->hotel_quantity) }}</span>@endif
                                </td>
                                <td>
                                    <x-badge-status :label="$team->isReadOnly() ? 'Expired' : $team->subscription_status->label()" :variant="$team->isReadOnly() ? 'danger' : $team->subscription_status->variant()" />
                                    @if ($team->awaitsTrialApproval())
                                        <form method="POST" action="{{ route('admin.teams.approve-trial', $team) }}" class="mt-1">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-success">Approve trial</button>
                                        </form>
                                    @endif
                                    @if ($team->isOnTrial())
                                        <span class="d-block small text-muted">AI cost so far ${{ number_format($aiCost[$team->id] ?? 0, 4) }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($team->plan === \App\Enums\Plan::Trial)
                                        <span class="small text-muted">Trial ends</span> {{ $team->trial_ends_at?->format('j M Y') ?? '—' }}
                                    @else
                                        {{ $team->current_period_ends_at?->format('j M Y') ?? '—' }}
                                    @endif
                                </td>
                                <td class="cv-num">{{ $money($mrr[$team->id] ?? null) }}</td>
                                <td class="cv-num">{{ $team->users_count }}</td>
                                <td class="cv-num">{{ $team->hotels_count }}</td>
                                <td class="cv-num">
                                    ${{ number_format($content[$team->id]['cost'] ?? 0, 4) }}
                                    <span class="d-block small text-muted">{{ $content[$team->id]['pieces'] ?? 0 }} {{ Str::plural('piece', $content[$team->id]['pieces'] ?? 0) }}</span>
                                </td>
                                <td>{{ $team->created_at->format('j M Y') }}</td>
                                <td style="min-width: 260px">
                                    <form method="POST" action="{{ route('admin.teams.extend-trial', $team) }}" class="d-flex gap-1 mb-1">
                                        @csrf
                                        <label class="visually-hidden" for="days-{{ $team->id }}">Days</label>
                                        <input id="days-{{ $team->id }}" type="number" name="days" value="7" min="1" max="90" class="form-control form-control-sm" style="width: 4.5rem">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Extend trial</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.teams.comp', $team) }}" class="d-flex flex-wrap gap-1">
                                        @csrf
                                        <label class="visually-hidden" for="comp-plan-{{ $team->id }}">Plan</label>
                                        <select id="comp-plan-{{ $team->id }}" name="plan" class="form-select form-select-sm" style="width: 6rem">
                                            @foreach ($paidPlans as $plan)
                                                <option value="{{ $plan->value }}">{{ $plan->label() }}</option>
                                            @endforeach
                                        </select>
                                        <label class="visually-hidden" for="comp-hotels-{{ $team->id }}">Hotels</label>
                                        <input id="comp-hotels-{{ $team->id }}" type="number" name="hotels" value="{{ max(1, $team->hotels_count) }}" min="1" class="form-control form-control-sm" style="width: 4rem" title="Hotels">
                                        <label class="visually-hidden" for="comp-until-{{ $team->id }}">Until</label>
                                        <input id="comp-until-{{ $team->id }}" type="date" name="until" class="form-control form-control-sm" style="width: 9.5rem" title="Until (empty = no end)">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Comp plan</button>
                                    </form>
                                    <a href="{{ route('admin.teams.private-plan', $team) }}" class="btn btn-sm btn-link px-0">{{ $team->isPrivate() ? 'Edit private plan' : 'Private plan…' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($teams->hasPages())
            <x-slot:footer>{{ $teams->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
