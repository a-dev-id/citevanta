<?php

namespace App\Mail\Billing;

use App\Mail\ThrottledMail;
use App\Models\Invoice;
use Illuminate\Mail\Mailables\Content;

/**
 * Base for emails about one invoice: amount, due date and how to pay.
 */
abstract class InvoiceMail extends ThrottledMail
{
    public function __construct(public Invoice $invoice) {}

    /**
     * @return array<string, mixed>
     */
    protected function invoiceData(): array
    {
        $timezone = $this->invoice->team->timezoneName();

        return [
            'invoice' => $this->invoice,
            'amount' => $this->invoice->format($this->invoice->amount_due),
            'due' => $this->invoice->due_at->copy()->setTimezone($timezone)->format('j M Y'),
            'url' => route('billing.invoices.show', $this->invoice),
            'bank' => $this->invoice->provider === 'manual' ? (array) config('citevanta.billing.bank') : null,
        ];
    }

    protected function invoiceContent(string $name): Content
    {
        return new Content(markdown: "mail.billing.{$name}", with: $this->invoiceData());
    }
}
