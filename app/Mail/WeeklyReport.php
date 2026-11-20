<?php

namespace App\Mail;

use App\Models\Team;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Monday's weekly report: one section per hotel with new scan results.
 */
class WeeklyReport extends ThrottledMail
{
    /**
     * @param  list<array<string, mixed>>  $hotels  see WeeklyReportBuilder::forHotel()
     */
    public function __construct(public User $user, public Team $team, public array $hotels) {}

    public function envelope(): Envelope
    {
        $first = $this->hotels[0] ?? null;
        $subject = count($this->hotels) === 1 && $first['visibility'] !== null
            ? "{$first['hotel']}: {$this->percent($first['visibility'])} AI visibility this week"
            : 'Your weekly AI visibility report';

        return new Envelope(subject: $subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-report',
            with: [
                'dashboardUrl' => route('dashboard'),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'percent' => fn (?float $value) => $this->percent($value),
                'signed' => fn (?float $value) => $value === null ? null : ($value > 0 ? '+' : '').rtrim(rtrim(number_format($value, 1), '0'), '.').' pts',
            ],
        );
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('weekly-report.unsubscribe', ['user' => $this->user->id]);
    }

    private function percent(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }
}
