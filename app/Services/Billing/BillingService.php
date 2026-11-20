<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Mail\Billing\InvoiceCreated;
use App\Mail\Billing\PaymentReceived;
use App\Mail\Billing\PaymentRejected;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\Xendit\XenditException;
use App\Services\Plans\PlanCatalog;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Plans, invoices and subscription state. Providers (BillingProviders) only
 * take payments; everything here works the same for every provider.
 *
 * - Trial or expired team chooses a plan: a "new" invoice; paying it starts
 *   a period from the payment date.
 * - Upgrade during a period: an invoice for the pro-rata difference; the
 *   new plan starts when it is paid.
 * - Downgrade, billing interval and hotel count: saved as next_* and applied
 *   at the next renewal.
 * - Renewal invoices, reminders, past_due and expiry: SubscriptionLifecycle.
 */
final class BillingService
{
    public const PROOF_DISK = 'local';

    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly BillingProviders $providers,
        private readonly BillingMailer $mailer,
        private readonly PlanCatalog $catalog,
    ) {}

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return $this->catalog->hasIdr() ? ['idr', 'usd'] : ['usd'];
    }

    /**
     * The team's currency; new teams with a hotel in Indonesia pay in IDR (once IDR prices are set).
     */
    public function currencyFor(Team $team): string
    {
        if ($team->currency !== null && in_array($team->currency, $this->currencies(), true)) {
            return $team->currency;
        }

        $inIndonesia = $team->hotels()->where('country', 'Indonesia')->exists();

        return $inIndonesia && in_array('idr', $this->currencies(), true) ? 'idr' : 'usd';
    }

    public function minimumHotels(Team $team): int
    {
        return max(1, $team->hotels()->count());
    }

    public function supportsAutomaticRenewal(): bool
    {
        return $this->providers->current()->supportsAutomaticRenewal();
    }

    public function quote(Plan $plan, BillingInterval $interval, int $hotels, string $currency): Quote
    {
        return $this->prices->quote($plan, $interval, $hotels, $currency);
    }

    /**
     * What the next renewal costs, with any scheduled changes.
     */
    public function renewalQuote(Team $team): ?Quote
    {
        if (! $team->hasPaidPeriod() || $team->is_comped || $team->billing_interval === null || $team->currency === null) {
            return null;
        }

        try {
            return $this->prices->quote(
                $team->next_plan ?? $team->plan,
                $team->next_interval ?? $team->billing_interval,
                $team->next_quantity ?? $team->hotel_quantity,
                $team->currency,
                $team,
            );
        } catch (BillingNotAllowed) {
            return null;
        }
    }

    /**
     * Monthly recurring revenue of a paying team in minor units, or null.
     *
     * @return array{currency: string, amount: int}|null
     */
    public function mrr(Team $team): ?array
    {
        if (! in_array($team->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            || ! $team->plan->isPaid() || $team->is_comped || $team->billing_interval === null || $team->currency === null) {
            return null;
        }

        try {
            $quote = $this->prices->quote($team->plan, $team->billing_interval, $team->hotel_quantity, $team->currency, $team);
        } catch (BillingNotAllowed) {
            return null;
        }

        return ['currency' => $team->currency, 'amount' => intdiv($quote->total, $team->billing_interval->months())];
    }

    /**
     * The owner chose a plan on Settings > Billing.
     *
     * @throws BillingNotAllowed
     */
    public function choosePlan(Team $team, Plan $plan, BillingInterval $interval, int $hotels, string $currency, PaymentMethod $method): BillingResult
    {
        if ($team->isPrivate()) {
            throw new BillingNotAllowed('Your Private plan is managed by our team. Contact us to change it.');
        }

        if (! in_array($plan, PlanCatalog::PAID, true)) {
            throw new BillingNotAllowed('Choose Starter or Pro.');
        }

        if (! in_array($currency, $this->currencies(), true)) {
            throw new BillingNotAllowed('This currency is not available.');
        }

        if ($hotels < $this->minimumHotels($team)) {
            throw new BillingNotAllowed('Your team has '.$this->minimumHotels($team).' hotels. Choose at least that many, or remove a hotel first.');
        }

        if ($method === PaymentMethod::Automatic && ! $this->supportsAutomaticRenewal()) {
            throw new BillingNotAllowed('Automatic renewal is not available yet. Pay by bank transfer.');
        }

        // Validates that the plan has a price in this currency.
        $quote = $this->prices->quote($plan, $interval, $hotels, $currency);

        if ($this->isRunning($team)) {
            return $this->changeRunningSubscription($team, $plan, $interval, $hotels, $currency, $method);
        }

        return $this->startSubscription($team, $plan, $interval, $quote, $method);
    }

    /**
     * Cancel at the end of the paid period.
     *
     * @throws BillingNotAllowed
     */
    public function cancel(Team $team): void
    {
        if (! in_array($team->subscription_status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) || ! $team->plan->isPaid()) {
            throw new BillingNotAllowed('There is no running subscription to cancel.');
        }

        if ($team->renewsAutomatically()) {
            $this->providers->forTeam($team)->cancelAutomaticRenewal($team);
        }

        $team->forceFill([
            'subscription_status' => SubscriptionStatus::Canceled,
            'cancel_at_period_end' => true,
            'provider_subscription_id' => null,
            'payment_method' => PaymentMethod::Manual,
        ])->save();

        $team->invoices()->open()->whereIn('kind', [InvoiceKind::Renewal->value, InvoiceKind::Upgrade->value])
            ->get()->each(fn (Invoice $invoice) => $this->void($invoice));
    }

    /**
     * Undo a cancellation before the period ends. Renewal is by invoice
     * (automatic renewal can be set up again by choosing the plan).
     *
     * @throws BillingNotAllowed
     */
    public function resume(Team $team): void
    {
        if ($team->subscription_status !== SubscriptionStatus::Canceled || $team->current_period_ends_at === null || $team->current_period_ends_at->isPast()) {
            throw new BillingNotAllowed('The subscription has ended. Choose a plan to start again.');
        }

        $team->forceFill(['subscription_status' => SubscriptionStatus::Active, 'cancel_at_period_end' => false])->save();
    }

    /**
     * Create an invoice, get it ready with the provider and email the owners.
     */
    public function createInvoice(
        Team $team,
        InvoiceKind $kind,
        Quote $quote,
        Plan $plan,
        BillingInterval $interval,
        CarbonInterface $dueAt,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        bool $automatic = false,
        bool $notify = true,
    ): Invoice {
        $provider = $this->providers->current();

        $invoice = DB::transaction(fn () => Invoice::create([
            'team_id' => $team->id,
            'number' => $this->nextNumber(),
            'kind' => $kind,
            'status' => InvoiceStatus::Pending,
            'provider' => $provider->name(),
            'automatic' => $automatic,
            'description' => $this->describe($kind, $plan, $interval, $quote->hotels),
            'plan' => $plan,
            'interval' => $interval,
            'period_starts_at' => $periodStart,
            'period_ends_at' => $periodEnd,
            'unique_code' => 0,
            'amount_due' => $quote->total,
            'due_at' => $dueAt,
        ] + $quote->toInvoiceAttributes()));

        try {
            $provider->prepareInvoice($invoice);
        } catch (XenditException $e) {
            // The "Pay" button tries again (BillingProvider::paymentUrl).
            Log::warning("Billing: could not prepare {$invoice->number} at {$provider->name()}: ".$e->getMessage());
        }

        if ($notify && ! $automatic) {
            $this->mailer->toOwners($team, fn () => new InvoiceCreated($invoice->fresh()));
        }

        return $invoice->fresh();
    }

    /**
     * Payment received (admin "Mark as paid", or a provider webhook). Safe to
     * call twice: an invoice is applied once.
     */
    public function markPaid(Invoice $invoice, ?CarbonInterface $paidAt = null, ?string $note = null, ?User $by = null, ?string $providerPaymentId = null): Invoice
    {
        $applied = DB::transaction(function () use ($invoice, $paidAt, $note, $by, $providerPaymentId): bool {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Paid || $locked->status === InvoiceStatus::Void) {
                return false;
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $paidAt ?? now(),
                'payment_note' => $note,
                'marked_paid_by' => $by?->id,
                'provider_payment_id' => $providerPaymentId ?? $locked->provider_payment_id,
            ])->save();

            $this->apply($locked);

            return true;
        });

        $invoice->refresh();

        if ($applied) {
            $this->mailer->toOwners($invoice->team, fn () => new PaymentReceived($invoice));
        }

        return $invoice;
    }

    /**
     * An admin could not find the payment. The owner is emailed and can pay or submit again.
     */
    public function reject(Invoice $invoice, string $reason): void
    {
        if (! $invoice->isOpen()) {
            throw new BillingNotAllowed('Only open invoices can be rejected.');
        }

        $invoice->forceFill(['status' => InvoiceStatus::Rejected, 'rejected_at' => now(), 'rejected_reason' => $reason])->save();

        $this->mailer->toOwners($invoice->team, fn () => new PaymentRejected($invoice));
    }

    /**
     * "I have paid", with an optional proof of payment (stored privately).
     */
    public function submitPayment(Invoice $invoice, ?UploadedFile $proof): void
    {
        if (! $invoice->isOpen()) {
            throw new BillingNotAllowed('This invoice is not waiting for payment.');
        }

        $attributes = ['status' => InvoiceStatus::Submitted, 'submitted_at' => now(), 'rejected_at' => null, 'rejected_reason' => null];

        if ($proof !== null) {
            $path = $proof->store('billing-proofs/'.$invoice->team_id, self::PROOF_DISK);

            if ($invoice->proof_path !== null) {
                Storage::disk(self::PROOF_DISK)->delete($invoice->proof_path);
            }

            $attributes += ['proof_path' => $path, 'proof_name' => mb_substr($proof->getClientOriginalName(), 0, 200)];
        }

        $invoice->forceFill($attributes)->save();
    }

    /**
     * Where "Pay now" goes for this invoice (null: pay by bank transfer).
     */
    public function paymentUrl(Invoice $invoice): ?string
    {
        if (! $invoice->isOpen()) {
            return null;
        }

        try {
            $provider = $this->providers->forInvoice($invoice);

            // Pays the invoice and sets up automatic renewal in one step.
            if ($invoice->automatic && $invoice->payment_url === null && $provider->supportsAutomaticRenewal()) {
                $invoice->team->forceFill(['billing_provider' => $provider->name()])->save();

                return $provider->startAutomaticRenewal($invoice->team, $invoice);
            }

            return $provider->paymentUrl($invoice);
        } catch (XenditException $e) {
            Log::warning("Billing: no payment link for {$invoice->number}: ".$e->getMessage());

            return null;
        }
    }

    public function void(Invoice $invoice): void
    {
        if (! $invoice->isOpen()) {
            return;
        }

        $this->providers->forInvoice($invoice)->voidInvoice($invoice);
        $invoice->forceFill(['status' => InvoiceStatus::Void])->save();
    }

    /**
     * Admin: give a trial team (or an expired one) more trial days.
     */
    public function extendTrial(Team $team, int $days): void
    {
        if ($this->isRunning($team) || ($team->subscription_status === SubscriptionStatus::Active && $team->plan->isPaid())) {
            throw new BillingNotAllowed('This team has a paid subscription.');
        }

        $from = $team->trial_ends_at !== null && $team->trial_ends_at->isFuture() ? $team->trial_ends_at : now();

        $team->forceFill([
            'plan' => Plan::Trial,
            'subscription_status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $from->copy()->addDays($days),
            'trial_ending_notified_at' => null,
        ])->save();
    }

    /**
     * Admin: a free plan until $until (null = no end). No invoices, no MRR.
     */
    public function comp(Team $team, Plan $plan, ?CarbonInterface $until, int $hotels): void
    {
        if ($team->renewsAutomatically()) {
            $this->providers->forTeam($team)->cancelAutomaticRenewal($team);
        }

        $team->forceFill([
            'plan' => $plan,
            'subscription_status' => SubscriptionStatus::Active,
            'is_comped' => true,
            'hotel_quantity' => max(1, $hotels),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => $until,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'next_plan' => null,
            'next_interval' => null,
            'next_quantity' => null,
            'provider_subscription_id' => null,
            'payment_method' => PaymentMethod::Manual,
        ])->save();

        $team->invoices()->open()->get()->each(fn (Invoice $invoice) => $this->void($invoice));
    }

    /**
     * A paid period that is still running (it may be past due or canceled).
     */
    public function isRunning(Team $team): bool
    {
        return $team->hasPaidPeriod()
            && ! $team->is_comped
            && $team->current_period_ends_at !== null
            && ! $team->isReadOnly();
    }

    /**
     * Apply a paid invoice to the team's subscription.
     */
    private function apply(Invoice $invoice): void
    {
        $team = Team::query()->lockForUpdate()->findOrFail($invoice->team_id);
        $paidAt = $invoice->paid_at ?? now();

        if ($invoice->kind === InvoiceKind::Upgrade) {
            $team->forceFill(['plan' => $invoice->plan, 'next_plan' => $team->next_plan === $invoice->plan ? null : $team->next_plan])->save();
            $this->syncAutomaticRenewal($team);

            return;
        }

        $continues = $invoice->kind === InvoiceKind::Renewal
            && $team->current_period_ends_at !== null
            && $team->subscription_status !== SubscriptionStatus::Expired
            && ! $team->isReadOnly();
        $start = $continues ? $team->current_period_ends_at->copy() : $paidAt->copy();
        $end = $start->copy()->addMonthsNoOverflow($invoice->interval->months());

        $invoice->forceFill(['period_starts_at' => $start, 'period_ends_at' => $end])->save();

        $team->forceFill([
            'plan' => $invoice->plan,
            'billing_interval' => $invoice->interval,
            'currency' => $invoice->currency,
            'hotel_quantity' => $invoice->hotels,
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_starts_at' => $start,
            'current_period_ends_at' => $end,
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
            'is_comped' => false,
            'next_plan' => null,
            'next_interval' => null,
            'next_quantity' => null,
        ])->save();

        if ($invoice->kind === InvoiceKind::New) {
            $team->invoices()->open()->where('kind', InvoiceKind::New->value)->whereKeyNot($invoice->id)
                ->get()->each(fn (Invoice $other) => $this->void($other));
        }
    }

    private function startSubscription(Team $team, Plan $plan, BillingInterval $interval, Quote $quote, PaymentMethod $method): BillingResult
    {
        $team->invoices()->open()->where('kind', InvoiceKind::New->value)->get()->each(fn (Invoice $invoice) => $this->void($invoice));

        $automatic = $method === PaymentMethod::Automatic;
        $invoice = $this->createInvoice(
            $team, InvoiceKind::New, $quote, $plan, $interval,
            dueAt: now()->addDays((int) config('citevanta.billing.due_days', 7)),
            automatic: $automatic,
        );

        if ($automatic) {
            $provider = $this->providers->current();
            $team->forceFill(['billing_provider' => $provider->name()])->save();

            return new BillingResult($invoice, $provider->startAutomaticRenewal($team, $invoice), 'Authorise your card or e-wallet to start the subscription.');
        }

        return new BillingResult($invoice, null, "Invoice {$invoice->number} created. Your plan starts as soon as it is paid.");
    }

    private function changeRunningSubscription(Team $team, Plan $plan, BillingInterval $interval, int $hotels, string $currency, PaymentMethod $method): BillingResult
    {
        if ($currency !== $team->currency) {
            throw new BillingNotAllowed('The currency can only change when a new subscription starts.');
        }

        $messages = [];
        $invoice = null;

        if ($plan->rank() > $team->plan->rank()) {
            $invoice = $this->upgrade($team, $plan);
            $messages[] = $invoice !== null
                ? "Upgrade invoice {$invoice->number} created. {$plan->label()} starts as soon as it is paid."
                : "You are now on {$plan->label()}.";
        }

        $next = [
            'next_plan' => $plan->rank() < $team->plan->rank() ? $plan : null,
            'next_interval' => $interval !== $team->billing_interval ? $interval : null,
            'next_quantity' => $hotels !== $team->hotel_quantity ? $hotels : null,
        ];

        if ($next['next_plan'] !== null || $next['next_interval'] !== null || $next['next_quantity'] !== null) {
            $messages[] = 'Your changes apply at the next renewal on '.$team->current_period_ends_at->setTimezone($team->timezoneName())->format('j M Y').'.';
        }

        if ($method === PaymentMethod::Manual && $team->renewsAutomatically()) {
            $this->providers->forTeam($team)->cancelAutomaticRenewal($team);
            $next += ['provider_subscription_id' => null];
            $messages[] = 'Automatic renewal is off. You will get an invoice before the next renewal.';
        } elseif ($method === PaymentMethod::Automatic && ! $team->renewsAutomatically() && $team->payment_method !== PaymentMethod::Automatic) {
            $messages[] = 'Automatic renewal starts with your next renewal payment.';
        }

        $team->forceFill($next + ['payment_method' => $method])->save();
        $this->syncAutomaticRenewal($team);
        $this->refreshOpenRenewal($team);

        return new BillingResult($invoice, null, $messages === [] ? 'Nothing changed.' : implode(' ', $messages));
    }

    /**
     * Upgrade invoice for the rest of the current period, or null when nothing is left to pay.
     */
    private function upgrade(Team $team, Plan $plan): ?Invoice
    {
        $current = $this->prices->quote($team->plan, $team->billing_interval, $team->hotel_quantity, $team->currency, $team);
        $upgraded = $this->prices->quote($plan, $team->billing_interval, $team->hotel_quantity, $team->currency);
        $difference = $this->prices->upgradeDifference($current, $upgraded, $team->current_period_starts_at ?? now(), $team->current_period_ends_at);

        $team->invoices()->open()->where('kind', InvoiceKind::Upgrade->value)->get()->each(fn (Invoice $invoice) => $this->void($invoice));

        if ($difference <= 0) {
            $team->forceFill(['plan' => $plan])->save();

            return null;
        }

        return $this->createInvoice(
            $team, InvoiceKind::Upgrade, $this->prices->adjustment($difference, $team->currency, $team->hotel_quantity), $plan, $team->billing_interval,
            dueAt: now()->addDays((int) config('citevanta.billing.due_days', 7))->min($team->current_period_ends_at),
            periodStart: now(),
            periodEnd: $team->current_period_ends_at,
        );
    }

    /**
     * A renewal invoice already sent goes stale when the plan changes: replace it.
     */
    private function refreshOpenRenewal(Team $team): void
    {
        $open = $team->invoices()->open()->where('kind', InvoiceKind::Renewal->value)->latest('id')->first();

        if ($open === null) {
            return;
        }

        $this->void($open);
        app(SubscriptionLifecycle::class)->createRenewalInvoice($team->fresh());
    }

    private function syncAutomaticRenewal(Team $team): void
    {
        if (! $team->renewsAutomatically() || ($quote = $this->renewalQuote($team)) === null) {
            return;
        }

        $this->providers->forTeam($team)->updateAutomaticRenewal($team, $quote->total, ($team->next_interval ?? $team->billing_interval)->months());
    }

    private function describe(InvoiceKind $kind, Plan $plan, BillingInterval $interval, int $hotels): string
    {
        $hotelsText = $hotels.' '.($hotels === 1 ? 'hotel' : 'hotels');

        return match ($kind) {
            InvoiceKind::Upgrade => "Upgrade to {$plan->label()}, {$hotelsText}, rest of the current period",
            default => "{$plan->label()} plan, {$hotelsText}, ".strtolower($interval->label()),
        };
    }

    /**
     * INV-YYYYMM-0001, counting up within the month.
     */
    private function nextNumber(): string
    {
        $prefix = config('citevanta.billing.invoice_prefix', 'INV').'-'.now()->format('Ym').'-';
        $last = Invoice::query()->where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');
        $sequence = $last !== null ? (int) substr((string) $last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
