<?php

namespace App\Jobs;

use App\Models\HotelGoogleLink;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleDiagnostics;
use App\Services\Google\GoogleSync;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Exactly one Google API call of a sync (see GoogleSync). A further page, and the next
 * date window, is another job. Quota errors wait and retry; access errors stop and are
 * shown on Settings > Connect Google.
 *
 * Built for a shared host where a cron run can be killed:
 *  - Releases (rate limit, quota) and killed runs do not use up attempts ($tries = 0): five failed
 *    jobs with MaxAttemptsExceeded were caused by exactly that. Only real errors count
 *    ($maxExceptions), with a backoff, and the job stops being retried after 48 hours.
 *  - The timeout (60 s) is below the cron job timeout (70 s) and the queue retry_after (180 s), so a hung
 *    call is retried, never run twice at once.
 *  - It is idempotent: a page is saved in one transaction (rows are upserted on their unique key and the
 *    window is cleaned only after its last page), so running a page twice cannot add rows, and a killed
 *    page simply runs again.
 *  - It is unique per step (link, type, dates, offset), so the same step is never queued twice.
 */
class GoogleSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 60;

    public int $uniqueFor = 1800;

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

    public function uniqueId(): string
    {
        return implode(':', ['google-sync', $this->linkId, $this->type, $this->from, $this->to, $this->offset]);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(48);
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

            return;
        }

        // The last window of the traffic or totals data is in: compare it with a small live GA4 request.
        if (in_array($this->type, [GoogleSync::GA4_TRAFFIC, GoogleSync::GA4_TOTALS], true)) {
            GoogleSelfCheckJob::dispatch($this->linkId);
        }
    }

    /**
     * Out of retries (or a hard error): say so on Settings > Connect Google; the daily gap check queues the
     * missing days again.
     */
    public function failed(?Throwable $exception): void
    {
        $link = HotelGoogleLink::query()->find($this->linkId);
        $reason = $exception === null ? 'unknown error' : GoogleDiagnostics::safe(class_basename($exception).': '.$exception->getMessage());

        $link?->forceFill(['last_sync_error' => mb_substr("A sync step ({$this->type}, {$this->from} to {$this->to}) failed: {$reason}. It is queued again by the daily check.", 0, 500)])->save();
    }
}
