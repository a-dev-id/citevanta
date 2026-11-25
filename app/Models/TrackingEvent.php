<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing the website tracking script saw: an AI referral visit or a booking click. No IP address, no user
 * agent, no query string and no person: the platform, the page path and the stay dates and guests.
 *
 * @property int $id
 * @property int $hotel_id
 * @property string $type ai_referral|booking_click
 * @property string|null $platform
 * @property string $path
 * @property CarbonInterface|null $checkin
 * @property CarbonInterface|null $checkout
 * @property int|null $nights
 * @property int|null $adults
 * @property int|null $children
 * @property CarbonInterface $occurred_at
 */
#[Fillable(['hotel_id', 'type', 'platform', 'path', 'checkin', 'checkout', 'nights', 'adults', 'children', 'occurred_at'])]
class TrackingEvent extends Model
{
    use HasFactory;

    public const AI_REFERRAL = 'ai_referral';

    public const BOOKING_CLICK = 'booking_click';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'checkin' => 'date',
            'checkout' => 'date',
            'occurred_at' => 'datetime',
        ];
    }
}
