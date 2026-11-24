<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Livewire\Hotels\RatesManager;
use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\RoomType;
use App\Models\Team;
use App\Models\User;
use App\Services\Rates\BookDirectUrl;
use App\Services\Rates\RateCalendar;
use App\Services\Rates\RatesCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Direct Rates Page: the owner's rate calendar, the public server-rendered
 * pages (and the rule that only entered rates are shown), the book-direct
 * redirect, and the sitemap, robots and llms.txt entries.
 */
class DirectRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: RoomType} */
    private function setUpHotel(bool $published = true, string $bookingUrl = 'https://book.nandinibali.com/?arrival={checkin}&departure={checkout}&adults={adults}'): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create([
            'name' => 'Nandini Bali', 'city' => 'Ubud', 'country' => 'Indonesia', 'website' => 'https://www.nandinibali.com',
            'currency' => 'IDR', 'booking_url' => $bookingUrl, 'rates_description' => 'Villas in the rice fields of Ubud.',
            'rates_inclusions' => "Breakfast for two\nAirport transfer", 'rates_published' => $published, 'setup_completed_at' => now(),
        ]);
        $room = RoomType::factory()->for($hotel)->create(['name' => 'Garden Villa']);

        return [$owner, $hotel, $room];
    }

    private function fill(Hotel $hotel, RoomType $room, array $overrides = []): int
    {
        return app(RateCalendar::class)->fill($hotel, $overrides + [
            'room_type_id' => $room->id, 'from' => '2026-12-24', 'to' => '2026-12-26', 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'is_available' => true, 'price' => 2500000, 'min_stay' => 1, 'note' => null,
        ]);
    }

    // --- the calendar -------------------------------------------------------

    public function test_a_bulk_fill_writes_one_row_per_night_for_the_chosen_weekdays_only(): void
    {
        [, $hotel, $room] = $this->setUpHotel();

        // 2026-12-01 is a Tuesday. Friday and Saturday nights of December: 4, 5, 11, 12, 18, 19, 25, 26.
        $count = $this->fill($hotel, $room, ['from' => '2026-12-01', 'to' => '2026-12-31', 'weekdays' => [5, 6], 'price' => 3000000, 'min_stay' => 2, 'note' => 'Weekend rate']);

        $this->assertSame(8, $count);
        $dates = $hotel->rates()->orderBy('date')->pluck('date')->map->toDateString()->all();
        $this->assertSame(['2026-12-04', '2026-12-05', '2026-12-11', '2026-12-12', '2026-12-18', '2026-12-19', '2026-12-25', '2026-12-26'], $dates);
        $rate = $hotel->rates()->where('date', '2026-12-04')->first();
        $this->assertSame('3000000.00', $rate->price);
        $this->assertSame(2, $rate->min_stay);
        $this->assertSame('Weekend rate', $rate->note);
        $this->assertTrue($rate->is_available);
    }

    public function test_filling_again_replaces_the_rate_of_a_night_and_a_closed_night_has_no_price(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-24']);
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-24', 'price' => 2750000]);

        $this->assertSame(1, $hotel->rates()->count());
        $this->assertSame('2750000.00', $hotel->rates()->first()->price);

        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-24', 'is_available' => false, 'price' => null]);
        $closed = $hotel->rates()->first();
        $this->assertFalse($closed->is_available);
        $this->assertNull($closed->price);
    }

    public function test_invalid_dates_prices_and_stays_are_rejected_and_nothing_is_saved(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $other = RoomType::factory()->for(Hotel::factory()->create())->create();

        $bad = [
            'from in the past' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
            'to before from' => ['from' => '2026-12-10', 'to' => '2026-12-01'],
            'not a date' => ['from' => 'soon', 'to' => '2026-12-01'],
            'more than 366 days' => ['from' => '2026-12-01', 'to' => '2028-01-31'],
            'price zero' => ['price' => 0],
            'price negative' => ['price' => -5],
            'price text' => ['price' => 'cheap'],
            'price missing while available' => ['price' => null],
            'min stay zero' => ['min_stay' => 0],
            'min stay too long' => ['min_stay' => 99],
            'no weekday' => ['weekdays' => []],
            'weekday out of range' => ['weekdays' => [8]],
            'someone elses room type' => ['room_type_id' => $other->id],
            'note too long' => ['note' => str_repeat('x', 256)],
        ];

        foreach ($bad as $label => $override) {
            try {
                $this->fill($hotel, $room, $override);
                $this->fail("Accepted: {$label}");
            } catch (ValidationException) {
                $this->assertSame(0, HotelRate::count(), $label);
            }
        }
    }

    public function test_the_currency_must_be_set_before_rates_can_be_entered(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $hotel->forceFill(['currency' => null])->save();

        $this->expectException(ValidationException::class);
        $this->fill($hotel->fresh(), $room);
    }

    public function test_removing_rates_brings_back_rates_on_request(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-28']);

        $removed = app(RateCalendar::class)->clear($hotel, $room->id, '2026-12-25', '2026-12-26', [1, 2, 3, 4, 5, 6, 7]);

        $this->assertSame(2, $removed);
        $this->assertSame(3, $hotel->rates()->count());
    }

    public function test_the_owner_is_warned_when_rates_were_last_saved_14_or_more_days_ago(): void
    {
        [$owner, $hotel, $room] = $this->setUpHotel();
        $calendar = app(RateCalendar::class);

        $this->assertSame(['has_rates' => false, 'stale' => false], array_intersect_key($calendar->freshness($hotel), ['has_rates' => 1, 'stale' => 1]));
        $this->actingAs($owner)->get(route('hotels.rates', $hotel))->assertSee('No rates entered yet');

        $this->fill($hotel, $room);
        $this->travel(13)->days();
        $this->assertFalse($calendar->freshness($hotel)['stale']);
        $this->actingAs($owner)->get(route('hotels.rates', $hotel))->assertDontSee('Your rates are')->assertSee('Rates last saved');

        $this->travel(1)->days();
        $this->assertTrue($calendar->freshness($hotel)['stale']);
        $this->actingAs($owner)->get(route('hotels.rates', $hotel))->assertSee('Your rates are 14 days old');

        $this->fill($hotel, $room, ['from' => now()->toDateString(), 'to' => now()->toDateString()]);
        $this->assertFalse($calendar->freshness($hotel)['stale'], 'saving again resets the age');
    }

    // --- the owner screen ---------------------------------------------------

    public function test_only_the_private_plan_and_the_hotels_team_can_open_the_rates_screen(): void
    {
        [$owner, $hotel] = $this->setUpHotel();
        $this->actingAs($owner)->get(route('hotels.rates', $hotel))->assertOk()->assertSee('Page settings');

        $standard = User::factory()->withTeam(Team::factory()->create(['plan' => Plan::Starter]))->create();
        $own = Hotel::factory()->for($standard->currentTeam)->create(['setup_completed_at' => now()]);
        $this->actingAs($standard)->get(route('hotels.rates', $own))->assertForbidden();
        $this->actingAs($owner)->get(route('hotels.rates', $own))->assertForbidden();
        Auth::logout();
        $this->get(route('hotels.rates', $hotel))->assertRedirect();
    }

    public function test_the_owner_saves_page_settings_with_validation(): void
    {
        [$owner, $hotel] = $this->setUpHotel(published: false);
        $component = Livewire::actingAs($owner)->test(RatesManager::class, ['hotel' => $hotel]);

        $component->set('settings.currency', 'rupiah')->set('settings.booking_url', 'javascript:alert(1)')->set('settings.rates_subdomain', 'https://rates.nandinibali.com/x')
            ->call('saveSettings')
            ->assertHasErrors(['settings.currency', 'settings.booking_url', 'settings.rates_subdomain']);

        $component->set('settings.currency', 'usd')->set('settings.booking_url', 'https://book.nandinibali.com/?a={checkin}')->set('settings.rates_subdomain', 'Rates.NandiniBali.com')
            ->set('settings.rates_published', true)->call('saveSettings')->assertHasNoErrors();

        $hotel->refresh();
        $this->assertSame('USD', $hotel->currency);
        $this->assertSame('rates.nandinibali.com', $hotel->rates_subdomain);
        $this->assertTrue($hotel->rates_published);
        $component->assertSee('rates.nandinibali.com')->assertSee('CNAME');
    }

    public function test_the_page_cannot_be_published_without_a_room_type(): void
    {
        [$owner, $hotel, $room] = $this->setUpHotel(published: false);
        $room->delete();

        Livewire::actingAs($owner)->test(RatesManager::class, ['hotel' => $hotel])->set('settings.rates_published', true)->call('saveSettings')
            ->assertHasErrors('settings.rates_published');
        $this->assertFalse($hotel->fresh()->rates_published);
    }

    public function test_room_types_can_be_added_renamed_and_removed_with_their_rates(): void
    {
        [$owner, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room);

        $component = Livewire::actingAs($owner)->test(RatesManager::class, ['hotel' => $hotel])
            ->set('roomName', 'Pool Suite')->set('roomDescription', 'With a private pool')->call('saveRoom')->assertHasNoErrors();
        $suite = $hotel->roomTypes()->where('name', 'Pool Suite')->first();
        $this->assertNotNull($suite);

        $component->call('editRoom', $suite->id)->set('roomName', 'Pool Suite Deluxe')->call('saveRoom');
        $this->assertSame('Pool Suite Deluxe', $suite->fresh()->name);

        $component->set('roomName', '')->call('saveRoom')->assertHasErrors('roomName');

        $component->call('deleteRoom', $room->id);
        $this->assertSame(0, $hotel->rates()->count());
        $this->assertNull(RoomType::find($room->id));
    }

    public function test_the_owner_fills_rates_removes_them_and_picks_a_date_from_the_calendar(): void
    {
        [$owner, $hotel, $room] = $this->setUpHotel();
        $component = Livewire::actingAs($owner)->test(RatesManager::class, ['hotel' => $hotel]);

        $component->set('fill.from', '2026-12-24')->set('fill.to', '2026-12-26')->set('fill.price', '2500000')->set('fill.min_stay', 2)->call('saveFill')
            ->assertHasNoErrors()->assertSee('3 nights saved.');
        $this->assertSame(3, $hotel->rates()->count());

        $component->set('fill.price', '')->call('saveFill')->assertHasErrors('fill.price');
        $component->set('fill.price', '2500000')->set('fill.from', '2026-10-01')->set('fill.to', '2026-10-05')->call('saveFill')->assertHasErrors('fill.from');
        $component->set('fill.from', '2026-12-26')->set('fill.to', '2026-12-24')->call('saveFill')->assertHasErrors('fill.to');

        $component->call('pickDate', '2026-12-25', $room->id)->assertSet('fill.from', '2026-12-25')->assertSet('fill.to', '2026-12-25');
        $component->call('clearFill')->assertSee('1 night removed');
        $this->assertSame(2, $hotel->rates()->count());

        $component->set('month', '2026-12')->assertSee('December 2026')->assertSee('2,500,000')->call('shiftMonth', 1)->assertSee('January 2027');
    }

    // --- the public pages ---------------------------------------------------

    public function test_the_overview_shows_the_lowest_entered_rate_and_when_it_was_updated(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-25', 'price' => 2500000]);
        $this->fill($hotel, $room, ['from' => '2026-12-26', 'to' => '2026-12-26', 'price' => 1900000]);
        RoomType::factory()->for($hotel)->create(['name' => 'Pool Suite']);

        $this->get('/rates/'.$hotel->slug)->assertOk()
            ->assertSee('Nandini Bali')->assertSee('Villas in the rice fields of Ubud.')->assertSee('Garden Villa')
            ->assertSee('IDR 1,900,000')->assertDontSee('IDR 2,500,000')
            ->assertSee('Pool Suite')->assertSee('Rates on request')
            ->assertSee('Rates last updated by the hotel: ', false)->assertSee('24 Nov 2026, 18:00 (Asia/Makassar)')
            ->assertSee('Breakfast for two')->assertSee('>Book direct</a>', false);
    }

    public function test_a_date_page_shows_the_rate_the_hotel_entered_and_a_multi_night_total(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-25', 'price' => 2500000]);
        $this->fill($hotel, $room, ['from' => '2026-12-26', 'to' => '2026-12-26', 'price' => 3100000, 'note' => 'Boxing day dinner included']);

        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertOk()
            ->assertSee('rates for 1 night from Thursday 24 December 2026')->assertSee('IDR 2,500,000')->assertSee('total for 1 night');

        $this->get('/rates/'.$hotel->slug.'/2026-12-24/3-nights')->assertOk()
            ->assertSee('IDR 8,100,000')->assertSee('total for 3 nights')->assertSee('about IDR 2,700,000 per night')
            ->assertSee('Fri 25 Dec: IDR 2,500,000')->assertSee('Boxing day dinner included');
    }

    public function test_the_honesty_rule_a_date_without_a_rate_says_rates_on_request_and_shows_no_price(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-25', 'price' => 2500000]);

        $none = $this->get('/rates/'.$hotel->slug.'/2027-02-14')->assertOk()->assertSee('Rates on request')->assertSee('The hotel has not entered a rate for these dates');
        $none->assertDontSee('IDR')->assertDontSee('total for')->assertSee('The hotel has not entered rates for these dates yet');

        // One night of the stay has no rate: no total, no average, no price at all.
        $this->get('/rates/'.$hotel->slug.'/2026-12-24/3-nights')->assertOk()->assertSee('Rates on request')->assertDontSee('IDR')->assertDontSee('total for 3 nights');

        $page = $this->get('/rates/'.$hotel->slug.'/2027-02-14')->getContent();
        $data = $this->jsonLd($page);
        $this->assertSame([], $this->offers($data), 'no Offer without an entered rate');
        $this->assertStringNotContainsString('"price"', $page);
    }

    public function test_closed_nights_and_minimum_stays_are_stated_not_priced(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-24', 'is_available' => false, 'price' => null]);
        $this->fill($hotel, $room, ['from' => '2026-12-30', 'to' => '2026-12-31', 'min_stay' => 3, 'price' => 2000000]);

        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertSee('Not available on these dates')->assertDontSee('IDR ', false);
        $this->get('/rates/'.$hotel->slug.'/2026-12-30/2-nights')->assertSee('Minimum stay 3 nights')->assertDontSee('IDR 4,000,000');
        $this->get('/rates/'.$hotel->slug.'/2026-12-30/3-nights')->assertSee('Rates on request', false); // the third night has no rate
    }

    public function test_the_json_ld_describes_the_hotel_and_the_offers_with_stable_ids(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-26', 'price' => 2500000]);

        $page = $this->get('/rates/'.$hotel->slug.'/2026-12-24/3-nights')->assertOk()->getContent();
        $graph = $this->jsonLd($page)['@graph'];
        $hotelNode = collect($graph)->firstWhere('@type', 'Hotel');
        $offer = $hotelNode['makesOffer'][0];

        $this->assertSame(url('/rates/nandini-bali').'#hotel', $hotelNode['@id']);
        $this->assertSame('Nandini Bali', $hotelNode['name']);
        $this->assertSame('Ubud', $hotelNode['address']['addressLocality']);
        $this->assertSame('Offer', $offer['@type']);
        $this->assertSame('7500000.00', $offer['price']);
        $this->assertSame('IDR', $offer['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $offer['availability']);
        $this->assertSame('Garden Villa', $offer['itemOffered']['name']);
        $this->assertSame('2026-12-24', $offer['availabilityStarts']);
        $this->assertSame('2026-12-27', $offer['availabilityEnds']);
        $this->assertSame(url('/rates/nandini-bali/2026-12-24/3-nights').'#room-'.$room->id, $offer['@id']);

        $again = $this->jsonLd($this->get('/rates/'.$hotel->slug.'/2026-12-24/3-nights')->getContent());
        $this->assertSame($offer['@id'], $again['@graph'][0]['makesOffer'][0]['@id'], 'ids do not change between loads');

        $webPage = collect($graph)->firstWhere('@type', 'WebPage');
        $this->assertSame('2026-11-24T10:00:00+00:00', $webPage['dateModified']);
    }

    public function test_hotel_text_cannot_break_out_of_the_page_or_the_json_ld(): void
    {
        [, $hotel] = $this->setUpHotel();
        $hotel->forceFill(['rates_description' => 'Nice </script><script>alert(1)</script> "villas" <b>bold</b>'])->save();

        $html = $this->get('/rates/'.$hotel->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertSame('Nice </script><script>alert(1)</script> "villas" <b>bold</b>', $this->jsonLd($html)['@graph'][0]['description']);
    }

    public function test_pages_that_should_not_exist_answer_404(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room);

        foreach ([
            '/rates/unknown-hotel', '/rates/'.$hotel->slug.'/2026-11-23', '/rates/'.$hotel->slug.'/2026-02-30', '/rates/'.$hotel->slug.'/2030-01-01',
            '/rates/'.$hotel->slug.'/2026-12-24/0-nights', '/rates/'.$hotel->slug.'/2026-12-24/99-nights', '/rates/'.$hotel->slug.'/not-a-date',
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $hotel->forceFill(['rates_published' => false])->save();
        $this->get('/rates/'.$hotel->slug)->assertNotFound();
        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertNotFound();

        $hotel->forceFill(['rates_published' => true])->save();
        $this->get('/rates/'.$hotel->slug)->assertOk();
        $hotel->team->forceFill(['plan' => Plan::Starter])->save();
        $this->get('/rates/'.$hotel->slug)->assertNotFound();
    }

    public function test_one_night_has_one_address_and_the_date_form_redirects_to_it(): void
    {
        [, $hotel] = $this->setUpHotel();

        $this->get('/rates/'.$hotel->slug.'/2026-12-24/1-nights')->assertRedirect(url('/rates/nandini-bali/2026-12-24'))->assertStatus(301);
        $this->get('/rates/'.$hotel->slug.'?checkin=2026-12-24&nights=3')->assertRedirect(url('/rates/nandini-bali/2026-12-24/3-nights'));
        $this->get('/rates/'.$hotel->slug.'?checkin=2026-12-24&nights=1')->assertRedirect(url('/rates/nandini-bali/2026-12-24'));
        $this->get('/rates/'.$hotel->slug.'?checkin=garbage')->assertOk();
    }

    public function test_pages_are_cached_and_the_cache_is_cleared_when_rates_change(): void
    {
        Cache::flush();
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room, ['from' => '2026-12-24', 'to' => '2026-12-24', 'price' => 2500000]);

        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertSee('IDR 2,500,000');

        // A change that bypasses the service is not seen: the page comes from the cache.
        HotelRate::query()->update(['price' => 1111111]);
        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertSee('IDR 2,500,000')->assertDontSee('IDR 1,111,111');

        // Saving rates through the calendar clears it.
        $this->fill($hotel->fresh(), $room, ['from' => '2026-12-24', 'to' => '2026-12-24', 'price' => 2600000]);
        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertSee('IDR 2,600,000')->assertDontSee('IDR 2,500,000');

        // So do the page settings and the room types.
        [$owner] = [$hotel->team->users()->first()];
        Livewire::actingAs($owner)->test(RatesManager::class, ['hotel' => $hotel])->set('settings.rates_inclusions', 'Welcome drink')->call('saveSettings');
        $this->get('/rates/'.$hotel->slug.'/2026-12-24')->assertSee('Welcome drink');
        $this->assertGreaterThan(2, $hotel->fresh()->rates_version);
    }

    // --- book direct --------------------------------------------------------

    public function test_book_direct_redirects_to_the_booking_engine_with_utm_tags(): void
    {
        [, $hotel] = $this->setUpHotel();

        $response = $this->get('/go/'.$hotel->slug.'?checkin=2026-12-24&nights=3&adults=4');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://book.nandinibali.com/?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('2026-12-24', $query['arrival']);
        $this->assertSame('2026-12-27', $query['departure']);
        $this->assertSame('4', $query['adults']);
        $this->assertSame('citevanta', $query['utm_source']);
        $this->assertSame('rates-page', $query['utm_medium']);
    }

    public function test_the_button_on_the_page_points_to_the_redirect_for_that_stay(): void
    {
        [, $hotel] = $this->setUpHotel();

        $this->get('/rates/'.$hotel->slug.'/2026-12-24/3-nights')->assertSee('/go/nandini-bali?checkin=2026-12-24&amp;nights=3', false);
        $this->get('/rates/'.$hotel->slug)->assertSee('href="'.url('/go/nandini-bali').'"', false);

        $hotel->forceFill(['booking_url' => null])->save();
        app(RatesCache::class)->forget($hotel);
        $this->get('/rates/'.$hotel->slug.'?x=1')->assertDontSee('>Book direct</a>', false);
        $this->get('/go/'.$hotel->slug)->assertNotFound();
    }

    public function test_a_plain_booking_url_keeps_its_query_and_fragment_and_our_utm_tags_win(): void
    {
        [, $hotel] = $this->setUpHotel(bookingUrl: 'https://book.example.com/hotel/12?lang=en&utm_source=other&utm_medium=email#rooms');
        $url = app(BookDirectUrl::class)->build($hotel, '2026-12-24', 2, 2);

        $this->assertSame('https://book.example.com/hotel/12?lang=en&utm_source=citevanta&utm_medium=rates-page#rooms', $url);
        $this->assertSame('https://book.example.com/hotel/12?lang=en&utm_source=citevanta&utm_medium=rates-page#rooms', $this->get('/go/'.$hotel->slug)->headers->get('Location'));
    }

    public function test_the_redirect_destination_can_never_be_changed_by_the_request(): void
    {
        [, $hotel] = $this->setUpHotel();

        $location = $this->get('/go/'.$hotel->slug.'?checkin=https://evil.example&url=https://evil.example&adults=999&nights=-4')->headers->get('Location');

        $this->assertStringStartsWith('https://book.nandinibali.com/?', $location);
        $this->assertStringNotContainsString('evil', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('20', $query['adults']);
        $this->assertSame('', $query['arrival']);

        $this->get('/go/unknown')->assertNotFound();
        $hotel->forceFill(['rates_published' => false])->save();
        $this->get('/go/'.$hotel->slug)->assertNotFound();
    }

    public function test_booking_url_validation(): void
    {
        foreach (['https://book.example.com', 'http://book.example.com/x?a={checkin}&b={checkout}&c={adults}', 'https://book.example.com/{checkin}/{adults}'] as $ok) {
            $this->assertTrue(BookDirectUrl::isValid($ok), $ok);
        }

        foreach (['javascript:alert(1)', 'ftp://x.com', '//evil.com', 'book.example.com', 'https://', 'https://bad host/', '', 'data:text/html,x'] as $bad) {
            $this->assertFalse(BookDirectUrl::isValid($bad), $bad);
        }
    }

    // --- discovery ----------------------------------------------------------

    public function test_published_pages_are_in_the_sitemap_robots_and_llms_txt_and_unpublished_ones_are_not(): void
    {
        [, $hotel, $room] = $this->setUpHotel();
        $this->fill($hotel, $room);
        $hidden = Hotel::factory()->for($hotel->team)->create(['name' => 'Hidden Villa', 'rates_published' => false, 'setup_completed_at' => now()]);
        $other = Hotel::factory()->for(Team::factory()->create(['plan' => Plan::Starter]))->create(['name' => 'Standard Hotel', 'rates_published' => true, 'setup_completed_at' => now()]);

        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.url('/rates/nandini-bali').'</loc>', false)->assertSee('<lastmod>2026-11-24</lastmod>', false)
            ->assertDontSee($hidden->slug)->assertDontSee($other->slug);

        $this->get('/robots.txt')->assertSee('Allow: /rates/')->assertSee('Disallow: /go/');

        $this->get('/llms.txt')->assertOk()->assertSee('## Direct rates')->assertSee('[Nandini Bali]('.url('/rates/nandini-bali').')', false)
            ->assertDontSee('Hidden Villa')->assertDontSee('Standard Hotel');

        $hotel->forceFill(['rates_published' => false])->save();
        $this->get('/llms.txt')->assertDontSee('## Direct rates');
        $this->get('/sitemap.xml')->assertDontSee('/rates/nandini-bali');
    }

    public function test_the_rates_pages_are_not_linked_from_the_public_marketing_pages(): void
    {
        [, $hotel] = $this->setUpHotel();

        foreach (['/', '/pricing', '/free-audit', '/contact'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('/rates/'.$hotel->slug, false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
        $this->assertNotEmpty($match, 'JSON-LD missing');

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function offers(array $data): array
    {
        return collect($data['@graph'])->firstWhere('@type', 'Hotel')['makesOffer'] ?? [];
    }
}
