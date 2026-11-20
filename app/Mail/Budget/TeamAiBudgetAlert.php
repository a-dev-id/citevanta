<?php

namespace App\Mail\Budget;

use App\Mail\ThrottledMail;
use App\Models\Team;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the owners of a private-plan team: their own OpenAI key used 50%, 80%
 * or 100% of the monthly budget they set.
 */
class TeamAiBudgetAlert extends ThrottledMail
{
    public function __construct(public Team $team, public int $percent, public float $spent, public float $budget) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->percent}% of your monthly AI budget used");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.budget.team-alert', with: ['url' => route('settings.ai-key')]);
    }
}
