<?php

namespace App\Jobs;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One tracking health check = one Google API call (see GoogleHealth).
 */
class GoogleHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $linkId, public string $check)
    {
        $this->onQueue('google');
    }

    public static function dispatchAll(HotelGoogleLink $link): void
    {
        foreach (GoogleHealth::CHECKS as $check) {
            self::dispatch($link->id, $check);
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->job === null ? [] : [new RateLimited('google-api')];
    }

    public function handle(GoogleHealth $health): void
    {
        $link = HotelGoogleLink::query()->with('hotel')->find($this->linkId);

        if ($link === null) {
            return;
        }

        try {
            $health->run($link, $this->check);
        } catch (GoogleApiException $e) {
            if ($e->isQuota() && $this->job !== null) {
                $this->release(900);

                return;
            }

            throw $e;
        }
    }
}
