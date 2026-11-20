<?php

namespace App\Mail\Billing;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An admin could not find the payment for the invoice.
 */
class PaymentRejected extends InvoiceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: "We could not confirm your payment for {$this->invoice->number}");
    }

    public function content(): Content
    {
        return $this->invoiceContent('payment-rejected');
    }
}
