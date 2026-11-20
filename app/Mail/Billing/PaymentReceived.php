<?php

namespace App\Mail\Billing;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentReceived extends InvoiceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment received for {$this->invoice->number}");
    }

    public function content(): Content
    {
        $team = $this->invoice->team;

        return new Content(markdown: 'mail.billing.payment-received', with: $this->invoiceData() + [
            'paidUntil' => $team->current_period_ends_at?->copy()->setTimezone($team->timezoneName())->format('j M Y'),
            'plan' => $team->plan->label(),
        ]);
    }
}
