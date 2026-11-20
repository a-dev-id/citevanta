@php
    use App\Enums\BillingInterval;
    use App\Enums\PaymentMethod;
    use App\Enums\SubscriptionStatus;
    use App\Services\Plans\PlanCatalog;

    $tz = $team->timezoneName();
    $date = fn ($at) => $at?->copy()->setTimezone($tz)->format('j M Y');
    $selectedPlan = old('plan', $team->plan->isPaid() ? ($team->next_plan ?? $team->plan)->value : 'starter');
    $selectedInterval = old('interval', ($team->next_interval ?? $team->billing_interval ?? BillingInterval::Monthly)->value);
    $selectedHotels = (int) old('hotels', max($minimumHotels, $team->next_quantity ?? $team->hotel_quantity));
    $selectedMethod = old('payment_method', ($team->payment_method ?? PaymentMethod::Manual)->value);
@endphp

<x-layouts::app title="Billing" :breadcrumbs="['Settings' => null, 'Billing' => null]">
    <x-flash />

    @if ($openInvoice)
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
            <span class="flex-grow-1">
                Invoice {{ $openInvoice->number }} ({{ $openInvoice->format($openInvoice->amount_due) }}) is {{ strtolower($openInvoice->status->label()) }}, due {{ $date($openInvoice->due_at) }}.
            </span>
            <a href="{{ route('billing.invoices.show', $openInvoice) }}" class="btn btn-sm btn-primary">View invoice</a>
        </div>
    @endif

    <div class="row gx-3">
        <div class="col-xl-5">
            <x-card title="Subscription" :collapsible="false" :flush="true">
                <table class="table mb-0">
                    <tbody>
                        <tr><th scope="row">Plan</th><td>{{ $team->plan->label() }}@if ($team->is_comped) <span class="text-muted">(free)</span>@endif</td></tr>
                        <tr><th scope="row">Status</th><td><x-badge-status :label="$team->subscription_status->label()" :variant="$team->subscription_status->variant()" /></td></tr>
                        <tr><th scope="row">Hotels</th><td class="cv-num">{{ $minimumHotels }} of {{ $team->limits()->hotels }}</td></tr>
                        @if ($team->subscription_status === SubscriptionStatus::Trialing)
                            <tr><th scope="row">Trial ends</th><td>{{ $date($team->trial_ends_at) ?? '—' }}</td></tr>
                        @elseif ($team->current_period_ends_at)
                            <tr><th scope="row">Billing</th><td>{{ $team->billing_interval?->label() ?? '—' }}, {{ strtoupper((string) $team->currency) }}</td></tr>
                            <tr><th scope="row">Paid until</th><td>{{ $date($team->current_period_ends_at) }}</td></tr>
                        @endif
                        @if ($team->subscription_status === SubscriptionStatus::PastDue)
                            <tr><th scope="row">Grace period ends</th><td>{{ $date($team->grace_ends_at) }}</td></tr>
                        @endif
                        @if ($renewal && ! $team->cancel_at_period_end)
                            <tr>
                                <th scope="row">Next renewal</th>
                                <td>{{ $date($team->current_period_ends_at) }} · <span class="cv-num">{{ \App\Services\Billing\Money::format($renewal->total, $renewal->currency) }}</span></td>
                            </tr>
                            <tr><th scope="row">Payment</th><td>{{ $team->renewsAutomatically() ? PaymentMethod::Automatic->label() : PaymentMethod::Manual->label() }}</td></tr>
                        @endif
                        @if ($team->hasScheduledChange())
                            <tr>
                                <th scope="row">From next renewal</th>
                                <td>
                                    {{ collect([
                                        $team->next_plan?->label(),
                                        $team->next_interval?->label(),
                                        $team->next_quantity ? $team->next_quantity.' '.Str::plural('hotel', $team->next_quantity) : null,
                                    ])->filter()->implode(', ') }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
                @if ($team->subscription_status === SubscriptionStatus::Canceled && $team->current_period_ends_at?->isFuture())
                    <x-slot:footer>
                        <form method="POST" action="{{ route('billing.resume') }}" class="d-flex flex-wrap align-items-center gap-2">
                            @csrf
                            <span class="small text-muted flex-grow-1">Canceled. Ends on {{ $date($team->current_period_ends_at) }}.</span>
                            <button type="submit" class="btn btn-sm btn-primary">Resume subscription</button>
                        </form>
                    </x-slot:footer>
                @elseif (in_array($team->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) && $team->plan->isPaid() && ! $team->is_comped)
                    <x-slot:footer>
                        <form method="POST" action="{{ route('billing.cancel') }}" class="d-flex flex-wrap align-items-center gap-2"
                              onsubmit="return confirm('Cancel the subscription? It stays active until {{ $date($team->current_period_ends_at) }}.')">
                            @csrf
                            <span class="small text-muted flex-grow-1">Cancel any time. The plan stays active until the end of the paid period.</span>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Cancel subscription</button>
                        </form>
                    </x-slot:footer>
                @endif
            </x-card>
        </div>

        <div class="col-xl-7">
            @if ($team->isPrivate())
                <x-card title="Private plan" :collapsible="false">
                    <p class="mb-0">Your plan, price and limits are set up by our team. Invoices arrive by email before each period; contact us to change anything.</p>
                </x-card>
            @else
            <x-card :title="$running ? 'Change plan' : 'Choose a plan'" :collapsible="false"
                    :subtitle="$running ? 'Upgrades start when the difference is paid. Downgrades, billing period and hotel count change at the next renewal.' : 'Prices per hotel per month. Your plan starts as soon as the invoice is paid.'">
                <form id="plan-form" method="POST" action="{{ route('billing.plan') }}" x-data="{ currency: @js(old('currency', $currency)) }" novalidate>
                    @csrf
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Plan</legend>
                        <div class="row gx-3">
                            @foreach ($plans as $plan)
                                <div class="col-sm-6">
                                    <label class="card p-3 mb-2 h-100" for="plan-{{ $plan['key'] }}">
                                        <span class="form-check">
                                            <input class="form-check-input" type="radio" name="plan" id="plan-{{ $plan['key'] }}" value="{{ $plan['key'] }}" @checked($selectedPlan === $plan['key'])>
                                            <span class="form-check-label fw-semibold">{{ $plan['name'] }}</span>
                                        </span>
                                        @foreach ($currencies as $code)
                                            <span class="d-block cv-num mt-1" x-show="currency === @js($code)" @if ($code !== old('currency', $currency)) style="display: none" @endif>
                                                {{ PlanCatalog::format($plan['monthly'][$code], $code) }} <span class="small text-muted">/ hotel / month</span>
                                            </span>
                                        @endforeach
                                        <span class="d-block small text-muted">
                                            {{ $plan['limits']->promptsPerHotel }} prompts, {{ $plan['limits']->competitors }} competitors, {{ $plan['limits']->languages }} languages
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        @error('plan')<div class="text-danger small">{{ $message }}</div>@enderror
                    </fieldset>

                    <div class="row gx-3">
                        <div class="col-sm-4 mb-3">
                            <label for="interval" class="form-label">Billing period</label>
                            <select id="interval" name="interval" class="form-select">
                                <option value="monthly" @selected($selectedInterval === 'monthly')>Monthly</option>
                                <option value="annual" @selected($selectedInterval === 'annual')>Annual ({{ $annualMonths }} × monthly)</option>
                            </select>
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label for="hotels" class="form-label">Hotels</label>
                            <input id="hotels" name="hotels" type="number" min="{{ $minimumHotels }}" max="500" value="{{ $selectedHotels }}"
                                   @class(['form-control', 'is-invalid' => $errors->has('hotels')]) aria-describedby="hotels-help">
                            <div id="hotels-help" class="form-text">At least {{ $minimumHotels }} (hotels you have now).</div>
                            @error('hotels')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label for="currency" class="form-label">Currency</label>
                            <select id="currency" name="currency" class="form-select" x-model="currency" @disabled($running && count($currencies) > 1)>
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected(old('currency', $currency) === $code)>{{ strtoupper($code) }}</option>
                                @endforeach
                            </select>
                            @if ($running && count($currencies) > 1)
                                <input type="hidden" name="currency" value="{{ $currency }}">
                            @endif
                        </div>
                    </div>

                    <fieldset class="mb-3">
                        <legend class="form-label fs-6">Payment</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="payment_method" id="method-manual" value="manual" @checked($selectedMethod === 'manual' || ! $automaticAvailable)>
                            <label class="form-check-label" for="method-manual">{{ PaymentMethod::Manual->label() }}</label>
                        </div>
                        @if ($automaticAvailable)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="method-automatic" value="automatic" @checked($selectedMethod === 'automatic')>
                                <label class="form-check-label" for="method-automatic">{{ PaymentMethod::Automatic->label() }}</label>
                            </div>
                        @endif
                    </fieldset>

                    <p class="small text-muted mb-0">
                        @foreach ($discounts as $discount)
                            {{ $discount['percent'] }}% off {{ ucfirst($discount['plan']) }} for {{ $discount['min_hotels'] }}+ hotels.
                        @endforeach
                        @if ((float) config('citevanta.billing.tax.rate') > 0)
                            Prices {{ config('citevanta.billing.tax.prices_include_tax') ? 'include' : 'exclude' }} {{ config('citevanta.billing.tax.name') }} {{ rtrim(rtrim(number_format((float) config('citevanta.billing.tax.rate'), 2), '0'), '.') }}%.
                        @endif
                    </p>
                </form>
                <x-slot:footer>
                    <div class="text-end">
                        <button type="submit" form="plan-form" class="btn btn-primary">{{ $running ? 'Update subscription' : 'Continue to payment' }}</button>
                    </div>
                </x-slot:footer>
            </x-card>
            @endif
        </div>
    </div>

    <x-card title="Invoices" :flush="true" :collapsible="false">
        @if ($invoices->isEmpty())
            <x-empty-state icon="fa-file-invoice" text="No invoices yet." />
        @else
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th scope="col">Invoice</th><th scope="col">Date</th><th scope="col">Description</th>
                            <th scope="col" class="cv-num">Amount</th><th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td><a href="{{ route('billing.invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                                <td>{{ $date($invoice->created_at) }}</td>
                                <td>{{ $invoice->description }}</td>
                                <td class="cv-num">{{ $invoice->format($invoice->amount_due) }}</td>
                                <td><x-badge-status :label="$invoice->isOverdue() ? 'Overdue' : $invoice->status->label()" :variant="$invoice->isOverdue() ? 'danger' : $invoice->status->variant()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($invoices->hasPages())
            <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
        @endif
    </x-card>
</x-layouts::app>
