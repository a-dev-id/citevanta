<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Team;
use App\Services\Billing\Xendit\XenditProvider;

/**
 * Picks the billing provider. New invoices use BILLING_PROVIDER; an existing
 * invoice keeps the provider it was created with, and an automatic renewal
 * stays with the provider that runs it. So switching from manual to xendit
 * changes nothing for running subscriptions until their next renewal invoice.
 */
final class BillingProviders
{
    public const NAMES = ['manual', 'xendit', 'fake'];

    public function current(): BillingProvider
    {
        return $this->get((string) config('citevanta.billing.provider', 'manual'));
    }

    public function get(?string $name): BillingProvider
    {
        return match ($name) {
            'xendit' => app(XenditProvider::class),
            'fake' => app(FakeBillingProvider::class),
            default => app(ManualBankTransferProvider::class),
        };
    }

    public function forInvoice(Invoice $invoice): BillingProvider
    {
        return $this->get($invoice->provider);
    }

    /** The provider that runs the team's automatic renewal. */
    public function forTeam(Team $team): BillingProvider
    {
        return $team->billing_provider !== null ? $this->get($team->billing_provider) : $this->current();
    }
}
