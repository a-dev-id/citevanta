<?php

namespace App\Services\Tracking;

use App\Models\HotelTracking;
use App\Models\TrackingEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Checks what the website script sent and stores it. Nothing that identifies a person is accepted or stored:
 * no IP address, no user agent, no query string (only the path), no free text. Anything that does not look
 * right is dropped, never repaired into something else.
 */
final class TrackingCollector
{
    /** The largest request body (bytes). */
    public const MAX_BODY = 2048;

    /**
     * @param  array<string, mixed>  $data  the decoded payload of the script
     */
    public function store(HotelTracking $tracking, array $data): ?TrackingEvent
    {
        $type = $data['t'] ?? null;
        $path = self::path($data['path'] ?? null);

        if ($path === null) {
            return null;
        }

        $attributes = ['hotel_id' => $tracking->hotel_id, 'path' => $path, 'occurred_at' => now()];

        if ($type === TrackingEvent::AI_REFERRAL) {
            $platform = $data['p'] ?? null;

            if (! is_string($platform) || ! in_array($platform, TrackingScript::platforms(), true)) {
                return null;
            }

            $event = TrackingEvent::query()->create($attributes + ['type' => TrackingEvent::AI_REFERRAL, 'platform' => $platform]);
        } elseif ($type === TrackingEvent::BOOKING_CLICK) {
            $checkin = self::date($data['ci'] ?? null, now()->subDay(), now()->addYears(2));
            $checkout = $checkin !== null ? self::date($data['co'] ?? null, $checkin->copy()->addDay(), $checkin->copy()->addDays(90)) : null;

            $event = TrackingEvent::query()->create($attributes + [
                'type' => TrackingEvent::BOOKING_CLICK,
                'checkin' => $checkin?->toDateString(), 'checkout' => $checkout?->toDateString(),
                'nights' => $checkin !== null && $checkout !== null ? (int) $checkin->diffInDays($checkout) : null,
                'adults' => self::count($data['a'] ?? null, 1), 'children' => self::count($data['c'] ?? null, 0),
            ]);
        } else {
            return null;
        }

        if ($tracking->last_seen_at === null || $tracking->last_seen_at->lt(now()->subMinute())) {
            $tracking->forceFill(['last_seen_at' => now()])->save();
        }

        return $event;
    }

    /** The path of the page: no query string, no fragment, visible ASCII only, at most 300 characters. */
    public static function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = explode('#', explode('?', $value, 2)[0], 2)[0];

        return preg_match('/^\/[\x21-\x7E]{0,299}$/', $path) === 1 ? $path : null;
    }

    private static function date(mixed $value, CarbonInterface $min, CarbonInterface $max): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->between($min->copy()->startOfDay(), $max->copy()->endOfDay()) ? $date : null;
    }

    private static function count(mixed $value, int $min): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= $min && (int) $value <= 20 ? (int) $value : null;
    }
}
