<?php

namespace App\Services\Rates;

use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\RoomType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a public rates page shows, and its Hotel and Offer JSON-LD.
 *
 * The honesty rule: only rates the hotel entered are shown, with the time
 * they were last saved. A night without a rate is "rates on request" and
 * never gets a price, a total or an Offer.
 *
 * Room statuses: from (overview, lowest entered nightly rate), available,
 * unavailable, min_stay (the stay is shorter than the minimum), on_request.
 */
final class RatesPage
{
    public function __construct(private readonly BookDirectUrl $book) {}

    /**
     * A hotel whose rates pages are public: published, on the Private plan.
     */
    public static function publishedHotel(string $slug): ?Hotel
    {
        $hotel = Hotel::query()->where('slug', $slug)->where('rates_published', true)->where('is_audit', false)->with('team')->first();

        return $hotel !== null && $hotel->team->isPrivate() ? $hotel : null;
    }

    public function url(Hotel $hotel, ?string $checkin = null, int $nights = 1): string
    {
        return match (true) {
            $checkin === null => route('rates.show', $hotel->slug),
            $nights > 1 => route('rates.stay', [$hotel->slug, $checkin, $nights]),
            default => route('rates.date', [$hotel->slug, $checkin]),
        };
    }

    /**
     * The overview: the lowest nightly rate the hotel entered for each room type, from today on.
     *
     * @return array<string, mixed>
     */
    public function overview(Hotel $hotel): array
    {
        $today = $this->today($hotel);
        $rates = $hotel->rates()->where('date', '>=', $today->toDateString())->where('date', '<=', $today->copy()->addDays((int) config('citevanta.rates.max_ahead_days'))->toDateString())
            ->where('is_available', true)->whereNotNull('price')->get()->groupBy('room_type_id');
        $rooms = [];

        foreach ($hotel->roomTypes as $room) {
            $own = $rates->get($room->id, collect());
            $lowest = $own->sortBy([['price', 'asc'], ['date', 'asc']])->first();

            $rooms[] = [
                'id' => $room->id, 'name' => $room->name, 'description' => $room->description,
                'status' => $lowest ? 'from' : 'on_request',
                'price' => $lowest ? (float) $lowest->price : null,
                'price_date' => $lowest?->date->toDateString(),
                'dates' => $own->count(),
                'last_date' => $own->max('date')?->toDateString(),
                'note' => $lowest?->note,
            ];
        }

        $updated = $rates->flatten()->max('updated_at');

        return $this->page($hotel, null, 1, $rooms, $updated ? Carbon::parse($updated) : null);
    }

    /**
     * One check-in date and a number of nights.
     *
     * @return array<string, mixed>
     */
    public function stay(Hotel $hotel, Carbon $checkin, int $nights): array
    {
        $dates = [];
        for ($i = 0; $i < $nights; $i++) {
            $dates[] = $checkin->copy()->addDays($i)->toDateString();
        }

        $rates = $hotel->rates()->whereIn('date', $dates)->get()->groupBy('room_type_id');
        $rooms = [];
        $updated = null;

        foreach ($hotel->roomTypes as $room) {
            $byDate = $rates->get($room->id, collect())->keyBy(fn (HotelRate $rate) => $rate->date->toDateString());
            $rooms[] = $this->stayRoom($room, $dates, $byDate);

            foreach ($byDate as $rate) {
                $updated = $updated === null || $rate->updated_at->gt($updated) ? $rate->updated_at : $updated;
            }
        }

        return $this->page($hotel, $checkin->toDateString(), $nights, $rooms, $updated);
    }

    /**
     * Hotel and Offer JSON-LD (as a JSON string). Offers exist only for rooms with an entered rate or a known sold-out night.
     *
     * @param  array<string, mixed>  $page  from overview() or stay()
     */
    public function jsonLd(array $page): string
    {
        /** @var Hotel $hotel */
        $hotel = $page['hotel'];
        $url = $page['url'];
        $offers = [];

        foreach ($page['rooms'] as $room) {
            if (! in_array($room['status'], ['from', 'available', 'unavailable'], true)) {
                continue;
            }

            $item = array_filter(['@type' => 'HotelRoom', 'name' => $room['name'], 'description' => $room['description']]);
            $offer = ['@type' => 'Offer', '@id' => $url.'#room-'.$room['id'], 'url' => $url, 'itemOffered' => $item, 'priceCurrency' => $page['currency']];

            if ($room['status'] === 'unavailable') {
                $offer['availability'] = 'https://schema.org/SoldOut';
            } else {
                $offer['availability'] = 'https://schema.org/InStock';
                $offer['price'] = number_format($room['status'] === 'available' ? $room['total'] : $room['price'], 2, '.', '');
                $offer['priceSpecification'] = [
                    '@type' => 'UnitPriceSpecification', 'priceCurrency' => $page['currency'], 'unitText' => 'night',
                    'price' => number_format($room['status'] === 'available' ? $room['average'] : $room['price'], 2, '.', ''),
                ];
            }

            if ($page['checkin'] !== null) {
                $offer['availabilityStarts'] = $page['checkin'];
                $offer['availabilityEnds'] = $page['checkout'];
            }

            $offers[] = $offer;
        }

        $graph = [
            array_filter([
                '@type' => 'Hotel', '@id' => route('rates.show', $hotel->slug).'#hotel',
                'name' => $hotel->name, 'url' => route('rates.show', $hotel->slug),
                'description' => $page['description'],
                'address' => array_filter(['@type' => 'PostalAddress', 'addressLocality' => $hotel->city, 'addressCountry' => $hotel->country]),
                'sameAs' => $hotel->website ? [$hotel->website] : null,
                'makesOffer' => $offers ?: null,
            ]),
            array_filter([
                '@type' => 'WebPage', '@id' => $url.'#page', 'url' => $url, 'name' => $page['title'],
                'about' => ['@id' => route('rates.show', $hotel->slug).'#hotel'],
                'dateModified' => $page['updated']?->toIso8601String(),
            ]),
        ];

        return (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * @param  array<string, HotelRate>|Collection<string, HotelRate>  $byDate
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function stayRoom(RoomType $room, array $dates, $byDate): array
    {
        $base = ['id' => $room->id, 'name' => $room->name, 'description' => $room->description, 'price' => null, 'total' => null, 'average' => null, 'nightly' => [], 'min_stay' => null, 'notes' => []];
        $nights = count($dates);
        $missing = array_filter($dates, fn ($date) => ! $byDate->has($date));
        $first = $byDate->get($dates[0]);

        if ($missing !== []) {
            return $base + ['status' => 'on_request'];
        }

        if ($byDate->contains(fn (HotelRate $rate) => ! $rate->is_available)) {
            return $base + ['status' => 'unavailable'];
        }

        if ($byDate->contains(fn (HotelRate $rate) => $rate->price === null)) {
            return $base + ['status' => 'on_request'];
        }

        if ($first->min_stay > $nights) {
            return ['min_stay' => $first->min_stay] + $base + ['status' => 'min_stay'];
        }

        $nightly = [];
        foreach ($dates as $date) {
            $nightly[$date] = (float) $byDate[$date]->price;
        }
        $total = array_sum($nightly);

        return [
            'status' => 'available', 'total' => $total, 'average' => $total / $nights, 'nightly' => $nightly,
            'notes' => array_values(array_unique(array_filter($byDate->map(fn (HotelRate $rate) => $rate->note)->all()))),
        ] + $base;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return array<string, mixed>
     */
    private function page(Hotel $hotel, ?string $checkin, int $nights, array $rooms, ?CarbonInterface $updated): array
    {
        $tz = $hotel->team->timezoneName();
        $checkout = $checkin !== null ? Carbon::parse($checkin)->addDays($nights)->toDateString() : null;
        $stay = $checkin !== null ? ' for '.$nights.' '.($nights === 1 ? 'night' : 'nights').' from '.Carbon::parse($checkin)->format('j M Y') : '';

        $page = [
            'hotel' => $hotel, 'currency' => (string) $hotel->currency, 'checkin' => $checkin, 'checkout' => $checkout, 'nights' => $nights,
            'url' => $this->url($hotel, $checkin, $nights), 'rooms' => $rooms,
            'title' => $hotel->name.' rates'.$stay.' | Book direct',
            'description' => filled($hotel->rates_description) ? trim((string) $hotel->rates_description) : null,
            'inclusions' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $hotel->rates_inclusions) ?: []))),
            'updated' => $updated,
            'updated_label' => $updated ? $updated->copy()->setTimezone($tz)->format('j M Y, H:i').' ('.$tz.')' : null,
            'book_url' => $this->book->build($hotel, $checkin, $nights) !== null
                ? route('rates.go', array_filter(['slug' => $hotel->slug, 'checkin' => $checkin, 'nights' => $checkin ? $nights : null]))
                : null,
        ];

        return $page + ['json_ld' => $this->jsonLd($page)];
    }

    private function today(Hotel $hotel): Carbon
    {
        return Carbon::parse(now($hotel->team->timezoneName())->toDateString());
    }
}
