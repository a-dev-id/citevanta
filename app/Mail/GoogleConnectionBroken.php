<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To admins, once: the Google agent token stopped working; GA4 and Search
 * Console syncs are paused until an admin reconnects.
 */
class GoogleConnectionBroken extends ThrottledMail
{
    public function __construct(public string $error) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Google connection needs to be reconnected');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.google-connection-broken', with: ['url' => route('admin.integrations.google')]);
    }
}
