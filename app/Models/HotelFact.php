<?php

namespace App\Models;

use Database\Factories\HotelFactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fact about the hotel as stated by the hotel team (price range, pool,
 * check-in time, ...). The source of truth for later accuracy checks.
 */
#[Fillable(['hotel_id', 'key', 'value', 'position'])]
class HotelFact extends Model
{
    /** @use HasFactory<HotelFactFactory> */
    use HasFactory;

    /**
     * Keys pre-filled in the setup wizard (config: citevanta.suggested_facts).
     *
     * @return list<string>
     */
    public static function suggestedKeys(): array
    {
        return array_values((array) config('citevanta.suggested_facts', []));
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
