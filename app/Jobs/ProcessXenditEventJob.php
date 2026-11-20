<?php

namespace App\Jobs;

use App\Models\XenditEvent;
use App\Services\Billing\Xendit\XenditWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies a stored Xendit webhook (see XenditWebhookProcessor), so the
 * webhook itself can answer 200 at once.
 */
class ProcessXenditEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(XenditWebhookProcessor $processor): void
    {
        $event = XenditEvent::query()->find($this->eventId);

        if ($event !== null) {
            $processor->process($event);
        }
    }

    public function failed(Throwable $e): void
    {
        XenditEvent::query()->whereKey($this->eventId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
