<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessXenditEventJob;
use App\Models\XenditEvent;
use App\Services\Billing\Xendit\XenditWebhookProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/xendit (no session, cookies or CSRF; see routes/webhooks.php).
 *
 * Checks the x-callback-token header against XENDIT_CALLBACK_TOKEN, stores
 * the event once and answers 200 at once; ProcessXenditEventJob applies it.
 * A repeated delivery is acknowledged and not queued again.
 */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.xendit.callback_token');
        $given = (string) $request->header('x-callback-token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $payload = $request->json()->all();

        if (! is_string($payload['event'] ?? null)) {
            return response()->json(['message' => 'Missing event.'], 422);
        }

        $key = XenditWebhookProcessor::eventKey($payload);

        if (XenditEvent::query()->where('event_key', $key)->exists()) {
            return response()->json(['message' => 'Already received.']);
        }

        try {
            $event = XenditEvent::create([
                'event_key' => $key,
                'event' => mb_substr($payload['event'], 0, 80),
                'object_id' => XenditWebhookProcessor::objectId($payload),
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Already received.']);
        }

        ProcessXenditEventJob::dispatch($event->id);

        return response()->json(['message' => 'Received.']);
    }
}
