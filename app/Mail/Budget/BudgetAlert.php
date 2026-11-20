<?php

namespace App\Mail\Budget;

use App\Mail\ThrottledMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To admins: a budget bucket (or the total) passed 50%, 80% or 100% this month.
 */
class BudgetAlert extends ThrottledMail
{
    public function __construct(public string $bucket, public int $percent, public float $spent, public float $cap) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->bucket}: {$this->percent}% of the monthly AI budget used");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.budget.alert', with: ['url' => route('admin.system')]);
    }
}
