<?php

namespace App\Jobs;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleSelfCheck;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One GA4 request after a sync: stored against live for the last 28 days (see GoogleSelfCheck).
 * A failed request is not an error for the hotel: the previous check result simply stays.
 */
class GoogleSelfCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $linkId)
    {
        $this->onQueue('google');
    }

    public function uniqueId(): string
    {
        return 'google-self-check:'.$this->linkId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->job === null ? [] : [new RateLimited('google-api')];
    }

    public function handle(GoogleSelfCheck $check): void
    {
        $link = HotelGoogleLink::query()->find($this->linkId);

        if ($link === null) {
            return;
        }

        try {
            $check->run($link);
        } catch (GoogleApiException $e) {
            if ($e->isQuota() && $this->job !== null) {
                $this->release(900);

                return;
            }

            if ($e->isAuth() || $e->isPermission() || $e->status === 400 || $e->status === 404) {
                return;
            }

            throw $e;
        }
    }
}
