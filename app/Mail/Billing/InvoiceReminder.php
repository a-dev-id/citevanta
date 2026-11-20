<?php

namespace App\Mail\Billing;

use App\Models\Invoice;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Reminder 3 days before the due date, and on the due day.
 */
class InvoiceReminder extends InvoiceMail
{
    public function __construct(Invoice $invoice, public bool $dueToday = false)
    {
        parent::__construct($invoice);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->dueToday
            ? "Invoice {$this->invoice->number} is due today"
            : "Reminder: invoice {$this->invoice->number} is due soon");
    }

    public function content(): Content
    {
        return $this->invoiceContent('invoice-reminder');
    }
}
