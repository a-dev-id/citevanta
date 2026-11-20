<?php

namespace App\Mail\Budget;

use App\Mail\ThrottledMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To admins, once: OpenAI refused a call for lack of credit or a spend limit.
 */
class OpenAiBillingStopped extends ThrottledMail
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'OpenAI credit or spend limit reached');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.budget.billing-stopped', with: ['url' => route('admin.system')]);
    }
}
