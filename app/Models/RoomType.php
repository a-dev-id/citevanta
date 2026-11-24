<?php

namespace App\Models;

use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room type of a hotel on the Direct Rates Page.
 *
 * @property int $id
 * @property int $hotel_id
 * @property string $name
 * @property string|null $description
 * @property int $position
 */
#[Fillable(['hotel_id', 'name', 'description', 'position'])]
class RoomType extends Model
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory;

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return HasMany<HotelRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(HotelRate::class);
    }
}
