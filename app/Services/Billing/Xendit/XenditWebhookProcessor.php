<?php

namespace App\Services\Billing\Xendit;

use App\Enums\InvoiceKind;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\XenditEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Applies one stored Xendit webhook. Idempotent: an event is processed once
 * (xendit_events.processed_at), and each handler checks state first, so the
 * same payment arriving under two events is applied once.
 *
 * Events: payment_session.completed / .expired, recurring.plan.activated /
 * .inactivated, recurring.cycle.succeeded / .retrying / .failed /
 * .force_attempt_failed. Anything else is stored and ignored.
 */
final class XenditWebhookProcessor
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * Build the idempotency key from a payload: the same event about the
     * same object in the same state is the same delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function eventKey(array $payload): string
    {
        $data = (array) ($payload['data'] ?? []);

        return hash('sha256', implode('|', [
            (string) ($payload['event'] ?? ''),
            (string) ($data['id'] ?? $data['payment_session_id'] ?? ''),
            (string) ($data['status'] ?? ''),
            (string) ($data['attempt_count'] ?? ''),
            (string) ($data['updated'] ?? ''),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function objectId(array $payload): ?string
    {
        $data = (array) ($payload['data'] ?? []);
        $id = $data['id'] ?? $data['payment_session_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function process(XenditEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $locked = XenditEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->processed_at !== null) {
                return;
            }

            $data = $locked->data();
            $handled = match ($locked->event) {
                'payment_session.completed' => $this->sessionCompleted($data),
                'payment_session.expired' => $this->sessionExpired($data),
                'recurring.plan.activated' => $this->planActivated($data),
                'recurring.plan.inactivated' => $this->planInactivated($data),
                'recurring.cycle.succeeded' => $this->cycleSucceeded($data),
                'recurring.cycle.retrying', 'recurring.cycle.failed', 'recurring.cycle.force_attempt_failed' => $this->cycleFailed($data),
                default => false,
            };

            $locked->forceFill(['status' => $handled ? 'processed' : 'ignored', 'processed_at' => now()])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sessionCompleted(array $data): bool
    {
        $invoice = $this->invoiceFor($data);

        if ($invoice === null) {
            return false;
        }

        // Automatic renewal set up: Xendit created a recurring plan. The
        // payment itself arrives as recurring.cycle.succeeded.
        if (($data['session_type'] ?? null) === 'SUBSCRIPTION' || $invoice->automatic) {
            if (! empty($data['recurring_plan_id'])) {
                $this->linkPlan($invoice->team, (string) $data['recurring_plan_id']);
            }

            return true;
        }

        $this->billing->markPaid($invoice, now(), 'Paid online (Xendit)', null, isset($data['payment_id']) ? (string) $data['payment_id'] : null);

        return true;
    }

    /**
     * The payment link expired: a new one is made when the customer clicks "Pay".
     *
     * @param  array<string, mixed>  $data
     */
    private function sessionExpired(array $data): bool
    {
        $invoice = $this->invoiceFor($data);

        if ($invoice === null || ! $invoice->isOpen() || $invoice->provider_reference !== ($data['payment_session_id'] ?? null)) {
            return false;
        }

        $invoice->forceFill(['provider_reference' => null, 'payment_url' => null])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function planActivated(array $data): bool
    {
        $team = $this->teamForPlan($data);

        if ($team === null) {
            return false;
        }

        $this->linkPlan($team, (string) $data['id']);

        return true;
    }

    /**
     * Automatic renewal stopped at Xendit: renew by invoice from now on.
     *
     * @param  array<string, mixed>  $data
     */
    private function planInactivated(array $data): bool
    {
        $team = Team::query()->where('provider_subscription_id', (string) ($data['id'] ?? ''))->first();

        if ($team === null) {
            return false;
        }

        $team->forceFill(['provider_subscription_id' => null, 'payment_method' => PaymentMethod::Manual])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cycleSucceeded(array $data): bool
    {
        $cycleId = (string) ($data['id'] ?? '');
        $team = $this->teamForCycle($data);

        if ($team === null || $cycleId === '' || Invoice::query()->where('provider_payment_id', $cycleId)->exists()) {
            return false;
        }

        // The first charge pays the invoice that set up automatic renewal.
        $invoice = $team->invoices()->open()->where('automatic', true)->oldest('id')->first()
            ?? $this->renewalInvoiceForCharge($team);

        if ($invoice === null) {
            return false;
        }

        $this->billing->markPaid($invoice, now(), 'Automatic renewal (Xendit)', null, $cycleId);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cycleFailed(array $data): bool
    {
        $team = $this->teamForCycle($data);

        return $team !== null && $team->current_period_ends_at?->isPast() && $this->lifecycle->pastDue($team);
    }

    private function renewalInvoiceForCharge(Team $team): ?Invoice
    {
        $quote = $this->billing->renewalQuote($team);
        $interval = $team->next_interval ?? $team->billing_interval;

        if ($quote === null || $interval === null) {
            return null;
        }

        $start = $team->current_period_ends_at ?? now();

        return $this->billing->createInvoice(
            $team, InvoiceKind::Renewal, $quote, $team->next_plan ?? $team->plan, $interval,
            dueAt: $start,
            periodStart: $start,
            periodEnd: $start->copy()->addMonthsNoOverflow($interval->months()),
            automatic: true,
        );
    }

    private function linkPlan(Team $team, string $planId): void
    {
        $team->forceFill([
            'provider_subscription_id' => $planId,
            'payment_method' => PaymentMethod::Automatic,
            'billing_provider' => 'xendit',
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function invoiceFor(array $data): ?Invoice
    {
        $sessionId = $data['payment_session_id'] ?? null;

        if (is_string($sessionId) && ($invoice = Invoice::query()->where('provider_reference', $sessionId)->first()) !== null) {
            return $invoice;
        }

        $number = $data['metadata']['invoice'] ?? $this->numberFromReference((string) ($data['reference_id'] ?? ''));

        return $number !== null ? Invoice::query()->where('number', $number)->where('provider', 'xendit')->first() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function teamForPlan(array $data): ?Team
    {
        $planId = (string) ($data['id'] ?? '');

        if ($planId !== '' && ($team = Team::query()->where('provider_subscription_id', $planId)->first()) !== null) {
            return $team;
        }

        if (isset($data['metadata']['team_id'])) {
            return Team::query()->find((int) $data['metadata']['team_id']);
        }

        $number = $this->numberFromReference((string) ($data['reference_id'] ?? ''));

        return $number !== null ? Invoice::query()->where('number', $number)->first()?->team : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function teamForCycle(array $data): ?Team
    {
        $planId = (string) ($data['plan_id'] ?? '');

        if ($planId !== '' && ($team = Team::query()->where('provider_subscription_id', $planId)->first()) !== null) {
            return $team;
        }

        return isset($data['metadata']['team_id']) ? Team::query()->find((int) $data['metadata']['team_id']) : null;
    }

    /**
     * "INV-202611-0001-ab12cd" -> "INV-202611-0001".
     */
    private function numberFromReference(string $reference): ?string
    {
        return preg_match('/^([A-Z]+-\d{6}-\d{4,})/', $reference, $match) === 1 ? $match[1] : null;
    }
}
