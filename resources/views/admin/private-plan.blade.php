@php
    $labels = [
        'hotels' => 'Hotels', 'prompts_per_hotel' => 'Prompts per hotel', 'competitors' => 'Competitors', 'languages' => 'Languages',
        'scans_per_month' => 'Scheduled scans per month', 'manual_scans_per_month' => 'Manual scans per month',
        'runs_per_prompt' => 'Runs per prompt', 'content_generations_per_month' => 'Content pieces per month',
    ];
    $current = [
        'hotels' => $limits->hotels, 'prompts_per_hotel' => $limits->promptsPerHotel, 'competitors' => $limits->competitors,
        'languages' => $limits->languages, 'scans_per_month' => $limits->scansPerMonth, 'manual_scans_per_month' => $limits->manualScansPerMonth,
        'runs_per_prompt' => $limits->runsPerPrompt, 'content_generations_per_month' => $limits->contentGenerationsPerMonth,
    ];
@endphp
<x-layouts::app :title="'Private plan: '.$team->name" :breadcrumbs="['Admin' => null, 'Teams' => route('admin.teams'), 'Private plan' => null]">
    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.teams.private-plan.update', $team) }}" novalidate>
        @csrf
        @method('PUT')
        <div class="row gx-3">
            <div class="col-xl-5">
                <x-card title="Price" subtitle="Per month for the whole team. The team uses its own OpenAI key: no AI cost for us." :collapsible="false">
                    <x-form-field name="price_usd" label="Monthly price (USD)" type="number" :value="old('price_usd', $team->private_price_usd)" />
                    <x-form-field name="price_idr" label="Monthly price (IDR)" type="number" :value="old('price_idr', $team->private_price_idr)" />
                    <div class="row gx-3">
                        <div class="col-6 mb-3">
                            <label for="currency" class="form-label">Invoice currency</label>
                            <select id="currency" name="currency" class="form-select">
                                @foreach (['idr' => 'IDR', 'usd' => 'USD'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('currency', $team->currency ?? 'idr') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="interval" class="form-label">Billing period</label>
                            <select id="interval" name="interval" class="form-select">
                                <option value="monthly" @selected(old('interval', $team->billing_interval?->value ?? 'monthly') === 'monthly')>Monthly</option>
                                <option value="annual" @selected(old('interval', $team->billing_interval?->value) === 'annual')>Annual</option>
                            </select>
                        </div>
                    </div>
                    <x-form-field name="paid_until" label="Paid until" type="date" :value="old('paid_until', $team->current_period_ends_at?->setTimezone($team->timezoneName())->toDateString())"
                                  help="Empty: keep the current date, or one month from today for a new private plan. Renewal invoices follow from this date." />
                </x-card>
            </div>
            <div class="col-xl-7">
                <x-card title="Limits" subtitle="Leave a field empty to use the Private plan default." :collapsible="false">
                    <div class="row gx-3">
                        @foreach ($editable as $key)
                            <div class="col-sm-6">
                                <x-form-field :name="'limits['.$key.']'" :id="'limit-'.$key" :label="$labels[$key] ?? $key" type="number"
                                              :value="old('limits.'.$key, $team->plan_limits[$key] ?? null)"
                                              :help="'Now: '.$current[$key]" />
                            </div>
                        @endforeach
                    </div>
                    <p class="small text-muted mb-0">Engines: every engine that is available ({{ implode(', ', $limits->engines) }}).</p>
                    <x-slot:footer>
                        <div class="text-end"><button type="submit" class="btn btn-primary">Save private plan</button></div>
                    </x-slot:footer>
                </x-card>
            </div>
        </div>
    </form>
</x-layouts::app>
