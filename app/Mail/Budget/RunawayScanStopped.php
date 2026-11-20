<?php

namespace App\Mail\Budget;

use App\Mail\ThrottledMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To admins: a scan made more AI calls than its plan allows and was stopped.
 */
class RunawayScanStopped extends ThrottledMail
{
    public function __construct(public int $scanRunId, public int $calls, public int $max) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Scan #{$this->scanRunId} stopped: too many AI calls");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.budget.runaway', with: ['url' => route('admin.scan-runs')]);
    }
}
