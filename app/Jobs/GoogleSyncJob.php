<?php

namespace App\Jobs;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Exactly one Google API call of a sync (see GoogleSync). A further page is
 * another job. Quota errors wait and retry; access errors stop and are
 * shown on Settings > Connect Google.
 */
class GoogleSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(
        public int $linkId,
        public string $type,
        public string $from,
        public string $to,
        public int $offset = 0,
    ) {
        $this->onQueue('google');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->job === null ? [] : [new RateLimited('google-api')];
    }

    public function handle(GoogleSync $sync): void
    {
        $link = HotelGoogleLink::query()->find($this->linkId);

        if ($link === null) {
            return;
        }

        try {
            $next = $sync->run($link, $this->type, $this->from, $this->to, $this->offset);
        } catch (GoogleApiException $e) {
            if ($e->isQuota() && $this->job !== null) {
                $this->release(900);

                return;
            }

            // Not shared any more, or the agent is disconnected: no retries.
            if ($e->isAuth() || $e->isPermission() || $e->status === 400 || $e->status === 404) {
                $link->forceFill(['last_sync_error' => $e->getMessage()])->save();

                return;
            }

            throw $e;
        }

        if ($next !== null) {
            self::dispatch($this->linkId, $this->type, $this->from, $this->to, $next);
        }
    }
}
