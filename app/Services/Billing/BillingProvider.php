<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Team;

/**
 * Where and how customers pay. Plans, prices, subscription states and limits
 * live in BillingService and never depend on the provider.
 */
interface BillingProvider
{
    /** Stored on invoices (invoices.provider) and teams (teams.billing_provider). */
    public function name(): string;

    /**
     * Get a new invoice ready to be paid: a unique transfer amount (manual)
     * or a payment link (Xendit). Called once, right after the invoice is created.
     */
    public function prepareInvoice(Invoice $invoice): void;

    /**
     * Where the "Pay" button goes, or null when the customer pays by bank
     * transfer using the details on the invoice.
     */
    public function paymentUrl(Invoice $invoice): ?string;

    /** Whether the provider can charge a saved card or e-wallet every period. */
    public function supportsAutomaticRenewal(): bool;

    /**
     * Start automatic renewal; the first charge pays $invoice. Returns the URL
     * where the customer authorises the card or e-wallet.
     */
    public function startAutomaticRenewal(Team $team, Invoice $invoice): string;

    /** Change the amount and period length charged from the next renewal on. */
    public function updateAutomaticRenewal(Team $team, int $amount, int $months): void;

    /** Stop future automatic charges. */
    public function cancelAutomaticRenewal(Team $team): void;

    /** The invoice will not be paid any more (replaced or canceled). */
    public function voidInvoice(Invoice $invoice): void;
}
