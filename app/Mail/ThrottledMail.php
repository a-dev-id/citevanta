<?php

namespace App\Mail;

use App\Jobs\Middleware\ThrottleMail;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every email the app sends: queued, and throttled to
 * MAIL_MAX_PER_HOUR. A throttled email is retried for up to 3 days
 * instead of counting failed attempts.
 */
abstract class ThrottledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleMail];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(3);
    }

    /** Seconds to wait before retrying after a mail server error. */
    public function backoff(): int
    {
        return 300;
    }
}
