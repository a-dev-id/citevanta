<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The website tracking script of a hotel: its public key (it is in the page source of the hotel's website, so
 * it is not a secret), the selectors and parameter names, and the result of the last installation check.
 *
 * @property int $id
 * @property int $hotel_id
 * @property string $public_key
 * @property bool $enabled
 * @property array<string, mixed>|null $settings selectors, params, inputs, date_format
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $last_checked_at
 * @property bool|null $installed
 * @property string|null $last_check_result
 * @property-read Hotel $hotel
 */
#[Fillable(['hotel_id', 'public_key', 'enabled', 'settings', 'last_seen_at', 'last_checked_at', 'installed', 'last_check_result'])]
class HotelTracking extends Model
{
    use HasFactory;

    protected $table = 'hotel_tracking';

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** A new public key: "cvk_" and 24 random letters and digits. */
    public static function newKey(): string
    {
        return 'cvk_'.Str::lower(Str::random(24));
    }

    /**
     * The tracking record of a hotel, created with a new key the first time.
     */
    public static function forHotel(Hotel $hotel): self
    {
        return self::query()->firstOrCreate(['hotel_id' => $hotel->id], ['public_key' => self::newKey(), 'enabled' => true]);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'installed' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
