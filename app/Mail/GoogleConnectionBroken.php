<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To admins, once: Google refused the service account key; GA4 and Search
 * Console syncs are paused until it is fixed (Admin > Integrations > Google > Test connection).
 */
class GoogleConnectionBroken extends ThrottledMail
{
    public function __construct(public string $error) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Google refused the service account key');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.google-connection-broken', with: ['url' => route('admin.integrations.google')]);
    }
}
