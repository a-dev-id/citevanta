<?php

namespace App\Mail\Billing;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A new invoice (first payment, renewal or upgrade) with how to pay it.
 */
class InvoiceCreated extends InvoiceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name')." invoice {$this->invoice->number}");
    }

    public function content(): Content
    {
        return $this->invoiceContent('invoice-created');
    }
}
