<?php

namespace App\Services\Billing\Xendit;

use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\BillingProvider;
use App\Services\Billing\Money;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Xendit (BILLING_PROVIDER=xendit).
 *
 * - Invoice paid by link (virtual account, QRIS, e-wallet, card): a Payment
 *   Session with session_type PAY and mode PAYMENT_LINK. Paid when the
 *   payment_session.completed webhook arrives.
 * - Automatic renewal: a Payment Session with session_type SUBSCRIPTION.
 *   The customer authorises a card or e-wallet; Xendit creates a recurring
 *   plan and charges every period (recurring.cycle.* webhooks).
 *
 * Webhooks are handled by XenditWebhookProcessor.
 */
class XenditProvider implements BillingProvider
{
    public function __construct(private readonly XenditClient $client) {}

    public function name(): string
    {
        return 'xendit';
    }

    public function prepareInvoice(Invoice $invoice): void
    {
        $invoice->forceFill(['amount_due' => $invoice->total])->save();

        if (! $invoice->automatic) {
            $this->createPaymentLink($invoice);
        }
    }

    public function paymentUrl(Invoice $invoice): ?string
    {
        if ($invoice->isOpen() && $invoice->payment_url === null && ! $invoice->automatic) {
            $this->createPaymentLink($invoice);
        }

        return $invoice->payment_url;
    }

    public function supportsAutomaticRenewal(): bool
    {
        return true;
    }

    public function startAutomaticRenewal(Team $team, Invoice $invoice): string
    {
        $session = $this->client->createSession($this->sessionBody($invoice, 'SUBSCRIPTION') + [
            'subscription' => [
                'schedule' => [
                    // The session schedule allows DAY, WEEK or MONTH: a year is 12 months.
                    'interval' => 'MONTH',
                    'interval_count' => $invoice->interval->months(),
                    'retry_interval' => 'DAY',
                    'retry_interval_count' => 1,
                    'total_retry' => 3,
                    'failed_attempt_notifications' => [1, 3],
                ],
                'failed_cycle_action' => 'RESUME',
            ],
        ] + $this->channels('automatic_channels'));

        $invoice->forceFill([
            'automatic' => true,
            'provider_reference' => $session['payment_session_id'] ?? null,
            'payment_url' => $session['payment_link_url'] ?? null,
        ])->save();

        return (string) ($session['payment_link_url'] ?? '');
    }

    public function updateAutomaticRenewal(Team $team, int $amount, int $months): void
    {
        if ($team->provider_subscription_id === null) {
            return;
        }

        $this->client->updatePlan($team->provider_subscription_id, [
            'amount' => Money::toMajor($amount, (string) $team->currency),
            'schedule' => ['interval' => 'MONTH', 'interval_count' => $months],
        ]);
    }

    public function cancelAutomaticRenewal(Team $team): void
    {
        if ($team->provider_subscription_id === null) {
            return;
        }

        try {
            $this->client->deactivatePlan($team->provider_subscription_id);
        } catch (XenditException $e) {
            // Already inactive at Xendit: nothing left to stop.
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    public function voidInvoice(Invoice $invoice): void
    {
        if ($invoice->provider_reference === null) {
            return;
        }

        try {
            $this->client->cancelSession($invoice->provider_reference);
        } catch (XenditException $e) {
            Log::warning('Xendit: could not cancel the payment link of '.$invoice->number.': '.$e->getMessage());
        }
    }

    private function createPaymentLink(Invoice $invoice): void
    {
        $session = $this->client->createSession($this->sessionBody($invoice, 'PAY') + $this->channels('invoice_channels'));

        $invoice->forceFill([
            'provider_reference' => $session['payment_session_id'] ?? null,
            'payment_url' => $session['payment_link_url'] ?? null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionBody(Invoice $invoice, string $type): array
    {
        $owner = $invoice->team->owners()->orderBy('users.id')->first();
        $expires = $invoice->due_at->copy()->addDays((int) config('services.xendit.link_valid_days', 7));

        return [
            // Unique per session: a new link for the same invoice gets a new suffix.
            'reference_id' => $invoice->number.'-'.Str::lower(Str::random(6)),
            'session_type' => $type,
            'mode' => 'PAYMENT_LINK',
            'amount' => Money::toMajor($invoice->amount_due, $invoice->currency),
            'currency' => strtoupper($invoice->currency),
            'country' => 'ID',
            'customer' => $this->customer($invoice->team, $owner),
            'description' => Str::limit(config('app.name').' '.$invoice->description.' ('.$invoice->number.')', 1000, ''),
            'expires_at' => ($type === 'PAY' && $expires->isFuture() ? $expires : now()->addDay())->toIso8601String(),
            'locale' => 'en',
            'metadata' => ['team_id' => (string) $invoice->team_id, 'invoice' => $invoice->number],
            'success_return_url' => route('billing.invoices.show', $invoice),
            'cancel_return_url' => route('billing.invoices.show', $invoice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Team $team, ?User $owner): array
    {
        return array_filter([
            'type' => 'INDIVIDUAL',
            'reference_id' => 'team-'.$team->id,
            'email' => $owner?->email,
            'individual_detail' => ['given_names' => Str::limit($owner->name ?? $team->name, 50, '')],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function channels(string $key): array
    {
        $channels = array_values((array) config("services.xendit.{$key}", []));

        return $channels === [] ? [] : ['allowed_payment_channels' => $channels];
    }
}
