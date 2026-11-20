<?php

namespace App\Mail;

use App\Models\Team;
use App\Services\Ai\TeamAiKey;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the owners of a private-plan team: OpenAI refused their key, so their
 * scans and content are paused until they fix it.
 */
class TeamAiKeyStopped extends ThrottledMail
{
    public function __construct(public Team $team, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your OpenAI key needs attention: scans are paused');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-ai-key-stopped', with: [
            'advice' => TeamAiKey::stopMessage($this->code),
            'url' => route('settings.ai-key'),
        ]);
    }
}
