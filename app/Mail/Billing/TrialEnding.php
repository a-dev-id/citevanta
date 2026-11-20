<?php

namespace App\Mail\Billing;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrialEnding extends TeamBillingMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' trial ends in 2 days');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing.trial-ending', with: $this->teamData() + [
            'endsAt' => $this->date($this->team->trial_ends_at),
        ]);
    }
}
