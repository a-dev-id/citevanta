<?php

namespace App\Services\Billing;

use App\Models\Invoice;

/**
 * Outcome of choosing a plan: the invoice to pay (if any), where to send
 * the customer (automatic renewal authorisation), and a message.
 */
final readonly class BillingResult
{
    public function __construct(
        public ?Invoice $invoice,
        public ?string $redirectUrl,
        public string $message,
    ) {}
}
