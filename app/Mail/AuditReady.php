<?php

namespace App\Mail;

use App\Models\Audit;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your AI visibility report is ready", with the link to the audit report,
 * in the language the audit was requested in.
 */
class AuditReady extends ThrottledMail
{
    public function __construct(public Audit $audit)
    {
        $this->locale($audit->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('audit.mail.subject', ['hotel' => $this->audit->hotel_name], $this->audit->locale));
    }

    public function content(): Content
    {
        $results = $this->audit->results();

        return new Content(markdown: 'mail.audit-ready', with: [
            'url' => route('audit.show', $this->audit->token),
            'count' => $results->questions_mentioned,
            'total' => $results->questions,
        ]);
    }
}
