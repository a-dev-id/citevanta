<?php

namespace App\Services\Rates;

use App\Models\Hotel;
use App\Models\HotelRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The owner's manual rate calendar: a price per room type per night, with a
 * minimum stay, availability and an optional note. Rates are entered per
 * date range and weekdays ("bulk fill"); each night is stored as one row, so
 * single dates can be edited later. Nothing is ever invented: a night with no
 * row has no rate.
 */
final class RateCalendar
{
    public function __construct(private readonly RatesCache $cache) {}

    /**
     * Validation rules for a bulk fill; $prefix is for forms that keep the fields in an array ("fill.").
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'room_type_id' => ['required', 'integer'],
            $prefix.'from' => ['required', 'date_format:Y-m-d'],
            $prefix.'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$prefix.'from'],
            $prefix.'weekdays' => ['required', 'array', 'min:1'],
            $prefix.'weekdays.*' => ['integer', 'between:1,7'],
            $prefix.'is_available' => ['boolean'],
            $prefix.'price' => ['nullable', 'required_if:'.$prefix.'is_available,true', 'numeric', 'min:0.01', 'max:999999999'],
            $prefix.'min_stay' => ['required', 'integer', 'between:1,'.(int) config('citevanta.rates.max_nights')],
            $prefix.'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Set the rate for every chosen weekday (1 = Monday ... 7 = Sunday) between two dates.
     *
     * @param  array{room_type_id: int, from: string, to: string, weekdays: list<int>, is_available: bool, price: float|int|string|null, min_stay: int, note?: string|null}  $input
     * @return int nights written
     *
     * @throws ValidationException
     */
    public function fill(Hotel $hotel, array $input): int
    {
        $data = Validator::make($input, self::rules())->validate();
        $room = $hotel->roomTypes()->whereKey($data['room_type_id'])->first();
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();

        $errors = match (true) {
            $room === null => ['room_type_id' => 'Choose one of your room types.'],
            blank($hotel->currency) => ['price' => 'Set the currency in the page settings first.'],
            $from->lt($this->today($hotel)) => ['from' => 'The start date cannot be in the past.'],
            $from->diffInDays($to) >= (int) config('citevanta.rates.max_fill_days') => ['to' => 'One fill can cover at most '.config('citevanta.rates.max_fill_days').' days.'],
            default => [],
        };

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $available = (bool) $data['is_available'];
        $now = now();
        $rows = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (in_array($day->dayOfWeekIso, array_map('intval', $data['weekdays']), true)) {
                $rows[] = [
                    'hotel_id' => $hotel->id, 'room_type_id' => $room->id, 'date' => $day->toDateString(),
                    'price' => $available ? round((float) $data['price'], 2) : null,
                    'min_stay' => (int) $data['min_stay'], 'is_available' => $available,
                    'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            HotelRate::query()->upsert($chunk, ['room_type_id', 'date'], ['price', 'min_stay', 'is_available', 'note', 'updated_at']);
        }

        $this->cache->forget($hotel);

        return count($rows);
    }

    /**
     * Remove the rates of a room type for the chosen weekdays between two dates: the page then says "rates on request" again.
     *
     * @param  list<int>  $weekdays
     * @return int nights removed
     */
    public function clear(Hotel $hotel, int $roomTypeId, string $from, string $to, array $weekdays): int
    {
        $count = 0;

        HotelRate::query()->where('hotel_id', $hotel->id)->where('room_type_id', $roomTypeId)
            ->whereBetween('date', [$from, $to])->orderBy('id')->each(function (HotelRate $rate) use ($weekdays, &$count): void {
                if (in_array($rate->date->dayOfWeekIso, $weekdays, true)) {
                    $rate->delete();
                    $count++;
                }
            });

        $this->cache->forget($hotel);

        return $count;
    }

    /**
     * Rates of one month, by room type and date.
     *
     * @return array<int, array<string, HotelRate>>
     */
    public function month(Hotel $hotel, Carbon $month): array
    {
        $rates = [];

        $hotel->rates()->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get()->each(function (HotelRate $rate) use (&$rates): void {
                $rates[$rate->room_type_id][$rate->date->toDateString()] = $rate;
            });

        return $rates;
    }

    /**
     * When the owner last saved a rate, and whether that is too long ago.
     *
     * @return array{has_rates: bool, last_updated: ?Carbon, days_since: ?int, stale: bool}
     */
    public function freshness(Hotel $hotel): array
    {
        $last = $hotel->rates()->max('updated_at');
        $last = $last !== null ? Carbon::parse($last) : null;
        $days = $last !== null ? (int) floor($last->diffInDays(now())) : null;

        return [
            'has_rates' => $last !== null,
            'last_updated' => $last,
            'days_since' => $days,
            'stale' => $days !== null && $days >= (int) config('citevanta.rates.stale_days'),
        ];
    }

    public function today(Hotel $hotel): Carbon
    {
        return Carbon::parse(now($hotel->team->timezoneName())->toDateString());
    }
}
