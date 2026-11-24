<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which GA4 property and Search Console site a hotel shared with our agent
 * account, its booking date mapping and the latest tracking health check.
 *
 * @property int $id
 * @property int $hotel_id
 * @property int $team_id
 * @property string|null $ga4_property "properties/123"
 * @property string|null $ga4_property_name
 * @property string|null $ga4_currency
 * @property string|null $ga4_timezone
 * @property string|null $gsc_site "https://www.example.com/" or "sc-domain:example.com"
 * @property array<string, string|null>|null $date_mapping checkin, checkout, adults, children, nights => GA4 parameter
 * @property array<string, array<string, mixed>>|null $health
 * @property CarbonInterface|null $health_checked_at
 * @property CarbonInterface|null $first_synced_at
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $booking_dates_synced_at
 * @property string|null $last_sync_error
 * @property array<string, mixed>|null $data_check stored against live GA4 after a sync: status ok|mismatch, checked_at, from, to, live, stored
 * @property-read Hotel $hotel
 */
#[Fillable([
    'hotel_id', 'team_id', 'ga4_property', 'ga4_property_name', 'ga4_currency', 'ga4_timezone', 'gsc_site',
    'date_mapping', 'health', 'health_checked_at', 'first_synced_at', 'last_synced_at', 'booking_dates_synced_at', 'last_sync_error', 'data_check',
])]
class HotelGoogleLink extends Model
{
    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** "properties/123456" -> "123456" */
    public function propertyId(): ?string
    {
        return $this->ga4_property !== null ? str_replace('properties/', '', $this->ga4_property) : null;
    }

    protected function casts(): array
    {
        return [
            'date_mapping' => 'array',
            'health' => 'array',
            'health_checked_at' => 'datetime',
            'first_synced_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'booking_dates_synced_at' => 'datetime',
            'data_check' => 'array',
        ];
    }
}
