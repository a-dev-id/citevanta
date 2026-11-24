<?php

namespace App\Jobs;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Exactly one Google API call of a sync (see GoogleSync). A further page, and the next
 * date window, is another job. Quota errors wait and retry; access errors stop and are
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
        public ?string $until = null,
        public ?string $batch = null,
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
            $batch = $this->batch ?? ($this->offset === 0 ? Str::lower(Str::random(12)) : null);
            $next = $sync->run($link, $this->type, $this->from, $this->to, $this->offset, $batch);
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
            self::dispatch($this->linkId, $this->type, $this->from, $this->to, $next, $this->until, $batch);

            return;
        }

        // A window is complete: start the next one, so data fills in from the oldest date to the newest.
        if ($this->until !== null && $this->to < $this->until) {
            $from = Carbon::parse($this->to)->addDay()->toDateString();
            self::dispatch($this->linkId, $this->type, $from, GoogleSync::windowEnd($from, $this->until), 0, $this->until);
        }
    }
}
