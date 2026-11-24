<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Services\Rates\BookDirectUrl;
use App\Services\Rates\RatesCache;
use App\Services\Rates\RatesPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The Direct Rates Page: public, server-rendered pages of the rates a hotel
 * entered (/rates/{slug}, /rates/{slug}/{date}, /rates/{slug}/{date}/{n}-nights)
 * and the "Book direct" redirect (/go/{slug}). A hotel that is not published,
 * or whose team left the Private plan, answers 404.
 */
class RatesController extends Controller
{
    public function __construct(private readonly RatesPage $pages, private readonly RatesCache $cache) {}

    public function show(Request $request, string $slug): Response|RedirectResponse
    {
        $hotel = $this->hotel($slug);

        // The date picker on the page is a plain GET form: send it to the stable URL of that stay.
        if (($checkin = $this->validDate((string) $request->query('checkin'), $hotel)) !== null) {
            $nights = $this->nights($request->query('nights'));

            return redirect($this->pages->url($hotel, $checkin->toDateString(), $nights));
        }

        return $this->respond($this->cache->remember($hotel, 'overview', fn () => view('site.rates', $this->pages->overview($hotel))->render()));
    }

    public function date(string $slug, string $date): Response
    {
        return $this->stay($slug, $date, 1);
    }

    public function stay(string $slug, string $date, int|string $nights): Response|RedirectResponse
    {
        $hotel = $this->hotel($slug);
        $checkin = $this->validDate($date, $hotel);
        $nights = (int) $nights;

        abort_if($checkin === null || $nights < 1 || $nights > (int) config('citevanta.rates.max_nights'), 404);

        // One night has one address.
        if ($nights === 1 && request()->routeIs('rates.stay')) {
            return redirect($this->pages->url($hotel, $checkin->toDateString()), 301);
        }

        return $this->respond($this->cache->remember(
            $hotel,
            "stay:{$date}:{$nights}",
            fn () => view('site.rates', $this->pages->stay($hotel, $checkin, $nights))->render(),
        ));
    }

    /**
     * Book direct: to the hotel's booking engine with our UTM tags. The destination is only ever the hotel's own setting.
     */
    public function go(Request $request, string $slug, BookDirectUrl $book): RedirectResponse
    {
        $hotel = $this->hotel($slug);
        $checkin = $this->validDate((string) $request->query('checkin'), $hotel);
        $adults = min(20, max(1, (int) $request->query('adults', 2)));
        $url = $book->build($hotel, $checkin?->toDateString(), $this->nights($request->query('nights')), $adults);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    private function hotel(string $slug): Hotel
    {
        return RatesPage::publishedHotel($slug) ?? abort(404);
    }

    /**
     * A real date from today (in the hotel's time zone) up to the booking window, or null.
     */
    private function validDate(string $value, Hotel $hotel): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if ($date === false || $date->toDateString() !== $value) {
            return null;
        }

        $today = Carbon::parse(now($hotel->team->timezoneName())->toDateString());

        return $date->gte($today) && $date->lte($today->copy()->addDays((int) config('citevanta.rates.max_ahead_days'))) ? $date : null;
    }

    private function nights(mixed $value): int
    {
        return min((int) config('citevanta.rates.max_nights'), max(1, (int) $value));
    }

    private function respond(string $html): Response
    {
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
