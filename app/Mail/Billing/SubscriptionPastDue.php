<?php

namespace App\Mail\Billing;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionPastDue extends TeamBillingMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' payment is overdue');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing.past-due', with: $this->teamData() + [
            'graceEndsAt' => $this->date($this->team->grace_ends_at),
        ]);
    }
}
