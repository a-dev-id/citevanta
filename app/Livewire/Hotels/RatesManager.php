<?php

namespace App\Livewire\Hotels;

use App\Models\Hotel;
use App\Services\Hotels\CurrentHotel;
use App\Services\Rates\BookDirectUrl;
use App\Services\Rates\RateCalendar;
use App\Services\Rates\RatesCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /hotels/{hotel}/rates (Private plan): the Direct Rates Page for the owner.
 * Page settings (currency, booking engine URL, description, what is included,
 * publishing), room types, a bulk fill by date range and weekday, and a month
 * calendar. Logic lives in RateCalendar, RatesCache and BookDirectUrl.
 */
class RatesManager extends Component
{
    #[Locked]
    public int $hotelId;

    /** @var array<string, mixed> */
    public array $settings = ['currency' => '', 'booking_url' => '', 'rates_description' => '', 'rates_inclusions' => '', 'rates_subdomain' => '', 'rates_published' => false];

    public string $roomName = '';

    public string $roomDescription = '';

    #[Locked]
    public ?int $editingRoom = null;

    /** @var array<string, mixed> */
    public array $fill = ['room_type_id' => '', 'from' => '', 'to' => '', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'is_available' => true, 'price' => '', 'min_stay' => 1, 'note' => ''];

    /** The month shown in the calendar, as "Y-m". */
    public string $month = '';

    public ?string $notice = null;

    public function mount(Hotel $hotel): void
    {
        Gate::authorize('update', $hotel);
        abort_unless($hotel->team->isPrivate(), 403, 'The Direct Rates Page is part of the Private plan.');
        app(CurrentHotel::class)->set($hotel);

        $this->hotelId = $hotel->id;
        $this->month = now($hotel->team->timezoneName())->format('Y-m');
        $this->settings = [
            'currency' => (string) ($hotel->currency ?? $hotel->googleLink?->ga4_currency ?? ''),
            'booking_url' => (string) $hotel->booking_url,
            'rates_description' => (string) $hotel->rates_description,
            'rates_inclusions' => (string) $hotel->rates_inclusions,
            'rates_subdomain' => (string) $hotel->rates_subdomain,
            'rates_published' => (bool) $hotel->rates_published,
        ];
        $this->fill['room_type_id'] = (string) ($hotel->roomTypes()->value('id') ?? '');
    }

    public function saveSettings(RatesCache $cache): void
    {
        $this->reset('notice');
        $hotel = $this->hotel();
        $this->settings['currency'] = strtoupper(trim((string) $this->settings['currency']));
        $this->settings['rates_subdomain'] = strtolower(trim((string) $this->settings['rates_subdomain']));

        $this->validate([
            'settings.currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'settings.booking_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! BookDirectUrl::isValid((string) $value)) {
                    $fail('Enter a full web address starting with https://. You can use {checkin}, {checkout} and {adults}.');
                }
            }],
            'settings.rates_description' => ['nullable', 'string', 'max:1000'],
            'settings.rates_inclusions' => ['nullable', 'string', 'max:2000'],
            'settings.rates_subdomain' => ['nullable', 'string', 'max:190', 'regex:/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', Rule::unique('hotels', 'rates_subdomain')->ignore($hotel->id)],
            'settings.rates_published' => ['boolean'],
        ], [
            'settings.currency.regex' => 'Use a 3-letter currency code, for example IDR or USD.',
            'settings.rates_subdomain.regex' => 'Enter only the host name, for example rates.yourhotel.com (no https:// and no path).',
        ]);

        if ($this->settings['rates_published'] && $hotel->roomTypes()->doesntExist()) {
            $this->addError('settings.rates_published', 'Add at least one room type before you publish the page.');

            return;
        }

        $hotel->forceFill([
            'currency' => $this->settings['currency'],
            'booking_url' => filled($this->settings['booking_url']) ? trim($this->settings['booking_url']) : null,
            'rates_description' => filled($this->settings['rates_description']) ? trim($this->settings['rates_description']) : null,
            'rates_inclusions' => filled($this->settings['rates_inclusions']) ? trim($this->settings['rates_inclusions']) : null,
            'rates_subdomain' => filled($this->settings['rates_subdomain']) ? strtolower(trim($this->settings['rates_subdomain'])) : null,
            'rates_published' => (bool) $this->settings['rates_published'],
        ])->save();
        $cache->forget($hotel);

        $this->notice = 'Page settings saved.';
    }

    public function saveRoom(RatesCache $cache): void
    {
        $this->reset('notice');
        $hotel = $this->hotel();
        $data = $this->validate([
            'roomName' => ['required', 'string', 'max:120'],
            'roomDescription' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->editingRoom !== null) {
            $hotel->roomTypes()->whereKey($this->editingRoom)->firstOrFail()->update(['name' => trim($data['roomName']), 'description' => filled($data['roomDescription']) ? trim($data['roomDescription']) : null]);
        } else {
            $room = $hotel->roomTypes()->create(['name' => trim($data['roomName']), 'description' => filled($data['roomDescription']) ? trim($data['roomDescription']) : null, 'position' => (int) $hotel->roomTypes()->max('position') + 1]);
            $this->fill['room_type_id'] = $this->fill['room_type_id'] ?: (string) $room->id;
        }

        $cache->forget($hotel);
        $this->reset('roomName', 'roomDescription', 'editingRoom');
        $this->notice = 'Room type saved.';
    }

    public function editRoom(int $id): void
    {
        $room = $this->hotel()->roomTypes()->whereKey($id)->firstOrFail();
        $this->editingRoom = $room->id;
        $this->roomName = $room->name;
        $this->roomDescription = (string) $room->description;
    }

    public function cancelRoomEdit(): void
    {
        $this->reset('roomName', 'roomDescription', 'editingRoom');
        $this->resetErrorBag();
    }

    public function deleteRoom(int $id, RatesCache $cache): void
    {
        $hotel = $this->hotel();
        $hotel->roomTypes()->whereKey($id)->firstOrFail()->delete();
        $cache->forget($hotel);

        if ((string) $id === (string) $this->fill['room_type_id']) {
            $this->fill['room_type_id'] = (string) ($hotel->roomTypes()->value('id') ?? '');
        }

        if ($hotel->roomTypes()->doesntExist() && $hotel->rates_published) {
            $hotel->forceFill(['rates_published' => false])->save();
            $this->settings['rates_published'] = false;
        }

        $this->notice = 'Room type removed with its rates.';
    }

    public function saveFill(RateCalendar $calendar): void
    {
        $this->reset('notice');
        $this->validate(RateCalendar::rules('fill.'));

        try {
            $count = $calendar->fill($this->hotel(), [...$this->fill, 'room_type_id' => (int) $this->fill['room_type_id'], 'weekdays' => array_map('intval', $this->fill['weekdays']), 'is_available' => (bool) $this->fill['is_available']]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('fill.'.$field, $messages[0]);
            }

            return;
        }

        $this->notice = "{$count} ".($count === 1 ? 'night' : 'nights').' saved.';
    }

    public function clearFill(RateCalendar $calendar): void
    {
        $this->reset('notice');
        $this->validate([
            'fill.room_type_id' => ['required', 'integer'],
            'fill.from' => ['required', 'date_format:Y-m-d'],
            'fill.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:fill.from'],
            'fill.weekdays' => ['required', 'array', 'min:1'],
        ]);

        $count = $calendar->clear($this->hotel(), (int) $this->fill['room_type_id'], $this->fill['from'], $this->fill['to'], array_map('intval', $this->fill['weekdays']));
        $this->notice = "{$count} ".($count === 1 ? 'night' : 'nights').' removed: the page says "rates on request" for them.';
    }

    /**
     * Click a date in the calendar: the fill form starts with that night.
     */
    public function pickDate(string $date, int $roomId): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->fill['from'] = $date;
            $this->fill['to'] = $date;
            $this->fill['room_type_id'] = (string) $roomId;
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('!Y-m', $this->month)->addMonthsNoOverflow($by)->format('Y-m');
    }

    public function render(RateCalendar $calendar): View
    {
        $hotel = $this->hotel();
        $month = Carbon::createFromFormat('!Y-m', $this->month);
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return view('livewire.hotels.rates-manager', [
            'hotel' => $hotel,
            'rooms' => $hotel->roomTypes()->get(),
            'monthDate' => $month,
            'days' => range(1, $month->daysInMonth),
            'rates' => $calendar->month($hotel, $month),
            'freshness' => $calendar->freshness($hotel),
            'host' => $host,
            'publicUrl' => route('rates.show', $hotel->slug),
            'stale' => (int) config('citevanta.rates.stale_days'),
        ])->layout('layouts::app', ['title' => 'Direct rates', 'breadcrumbs' => ['Hotels' => route('hotels.index'), $hotel->name => route('hotels.show', $hotel), 'Direct rates' => null]]);
    }

    private function hotel(): Hotel
    {
        $hotel = Hotel::query()->with('team')->findOrFail($this->hotelId);
        Gate::authorize('update', $hotel);
        abort_unless($hotel->team->isPrivate(), 403);

        return $hotel;
    }
}
