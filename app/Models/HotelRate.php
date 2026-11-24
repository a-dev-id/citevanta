<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\HotelRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the hotel entered for one room type on one night (the price is for
 * the night, in the hotel's currency). updated_at is shown publicly as the
 * "last updated" time.
 *
 * @property int $id
 * @property int $hotel_id
 * @property int $room_type_id
 * @property CarbonInterface $date
 * @property string|null $price
 * @property int $min_stay
 * @property bool $is_available
 * @property string|null $note
 */
#[Fillable(['hotel_id', 'room_type_id', 'date', 'price', 'min_stay', 'is_available', 'note'])]
class HotelRate extends Model
{
    /** @use HasFactory<HotelRateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'price' => 'decimal:2',
            'min_stay' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
