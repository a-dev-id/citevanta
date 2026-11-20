<?php

namespace App\Mail;

use App\Models\AuditWaitlist;
use App\Services\Site\PublicPages;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Free audit slots are open again" for someone on the audit waitlist.
 */
class AuditSlotsOpen extends ThrottledMail
{
    public function __construct(public AuditWaitlist $entry)
    {
        $this->locale($entry->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('audit.mail.slots_subject', [], $this->entry->locale));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.audit-slots-open', with: ['url' => PublicPages::url('free-audit', $this->entry->locale)]);
    }
}
