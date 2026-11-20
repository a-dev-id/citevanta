<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message from the website's contact form, to the admin.
 */
class ContactMessage extends ThrottledMail
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $company,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: config('app.name').' contact form: '.$this->senderName,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.contact');
    }
}
