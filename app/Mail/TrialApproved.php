<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An admin approved the trial (TRIAL_REQUIRES_APPROVAL): the first scan starts.
 */
class TrialApproved extends ThrottledMail
{
    public function __construct(public Team $team) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' account is ready');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.trial-approved', with: ['url' => route('dashboard')]);
    }
}
