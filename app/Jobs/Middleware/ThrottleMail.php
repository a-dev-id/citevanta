<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends at most citevanta.mail.max_per_hour emails per hour (shared hosting
 * limits outgoing mail). Over the limit, the job goes back to the queue
 * until the hour window frees up; nothing is dropped.
 */
final class ThrottleMail
{
    public const KEY = 'citevanta-outgoing-mail';

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $max = max(1, (int) config('citevanta.mail.max_per_hour', 100));

        if (RateLimiter::tooManyAttempts(self::KEY, $max)) {
            $job->release(max(1, RateLimiter::availableIn(self::KEY)));

            return null;
        }

        RateLimiter::hit(self::KEY, 3600);

        return $next($job);
    }
}
