<?php

namespace App\Mail\Billing;

use App\Models\Team;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SubscriptionExpired extends TeamBillingMail
{
    public function __construct(Team $team, public bool $wasTrial = false)
    {
        parent::__construct($team);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->wasTrial
            ? 'Your '.config('app.name').' trial has ended'
            : 'Your '.config('app.name').' subscription has expired');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.billing.expired', with: $this->teamData() + ['wasTrial' => $this->wasTrial]);
    }
}
