<?php

namespace Tests\Feature\Private;

use App\Console\Commands\HousekeepingCommand;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Livewire\Settings\WebsiteScript;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\HotelTracking;
use App\Models\Team;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\Google\AiSources;
use App\Services\Navigation\SidebarMenu;
use App\Services\Reports\AiTrafficReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\TableOptions;
use App\Services\Tracking\SafeSiteFetcher;
use App\Services\Tracking\TrackingScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The website tracking script (Part C): the script itself (run in Node when it is installed), the endpoint rules,
 * the no-IP promise, the Settings screen, the safe installation check and the AI traffic report numbers.
 * Today is 24 Nov 2026.
 */
class WebsiteScriptTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://www.nandini-hotel.com';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    /** @return array{0: User, 1: Hotel, 2: HotelTracking} */
    private function privateHotel(array $team = []): array
    {
        $team = Team::factory()->create($team + ['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => 'https://www.nandini-hotel.com', 'booking_engine_domain' => 'book.nandini-hotel.com', 'setup_completed_at' => now()]);

        return [$owner, $hotel, HotelTracking::forHotel($hotel)];
    }

    /** @param array<string, mixed> $payload */
    private function collect(array $payload, ?string $origin = self::ORIGIN, array $server = [])
    {
        return $this->call('POST', route('tracking.collect'), [], [], [], array_filter([
            'CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => $origin, 'REMOTE_ADDR' => '203.0.113.77', 'HTTP_USER_AGENT' => 'SecretAgent/9.9',
        ]) + $server, json_encode($payload));
    }

    // --- the script --------------------------------------------------------------------

    public function test_the_script_is_served_per_key_without_cookies_and_unknown_or_off_keys_get_nothing(): void
    {
        [, $hotel, $tracking] = $this->privateHotel();

        $response = $this->get(route('tracking.script', $tracking->public_key))->assertOk();

        $this->assertStringStartsWith('application/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'no cookie is set');
        $this->assertNull($response->headers->get('Set-Cookie'));
        $js = $response->getContent();
        foreach ([$tracking->public_key, route('tracking.collect'), 'chatgpt.com', 'perplexity.ai', 'book.nandini-hotel.com', '[data-booking]', 'booking_click', 'ai_referral'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        foreach (['document.cookie', 'localStorage', 'sessionStorage', 'indexedDB', 'canvas', 'userAgent', 'XMLHttpRequest', 'import(', 'require('] as $never) {
            $this->assertStringNotContainsString($never, $js, "the script never uses {$never}");
        }

        $this->get('/t/cvk_unknownunknownunknown1234.js')->assertNotFound();
        $tracking->update(['enabled' => false]);
        $this->get(route('tracking.script', $tracking->public_key))->assertNotFound();

        [, , $other] = $this->privateHotel(['plan' => Plan::Starter]);
        $this->get(route('tracking.script', $other->public_key))->assertNotFound();
    }

    public function test_a_hotels_settings_change_the_script_and_cannot_break_out_of_it(): void
    {
        [, , $tracking] = $this->privateHotel();
        $tracking->update(['settings' => ['selectors' => ['a.reserve</script><script>alert(1)'], 'params' => ['checkin' => ['arr']], 'date_format' => 'mdy']]);

        $js = $this->get(route('tracking.script', $tracking->public_key))->getContent();

        $this->assertStringContainsString('a.reserve', $js);
        $this->assertStringNotContainsString('</script>', $js, 'tags are escaped inside the script');
        $this->assertStringContainsString('"df":"mdy"', $js);
        $this->assertStringContainsString('"checkin":["arr"]', $js);
    }

    public function test_the_ai_list_has_one_platform_name_per_assistant(): void
    {
        $platforms = TrackingScript::platforms();

        foreach (['ChatGPT', 'Perplexity', 'Gemini', 'Copilot', 'Claude'] as $name) {
            $this->assertContains($name, $platforms);
        }
        $this->assertSame($platforms, array_values(array_unique($platforms)));
        $this->assertSame('ChatGPT', TrackingScript::aiMap()['chatgpt.com']);
    }

    public function test_normal_google_traffic_is_not_gemini_but_gemini_and_its_subdomains_are(): void
    {
        foreach (['gemini.google.com' => 'Gemini', 'chatgpt.com' => 'ChatGPT', 'www.chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'https://claude.ai/chat' => 'Claude'] as $source => $platform) {
            $this->assertSame($platform, AiSources::platform($source), $source);
        }

        foreach (['google.com', 'google', 'www.google.com', 'bing.com', 'notchatgpt.com', '(direct)', '', 'x.ai.example.com', 'microsoft.com'] as $source) {
            $this->assertNull(AiSources::platform($source), $source);
        }
    }

    // --- the script in a fake browser (Node) ------------------------------------------------

    /** @return array<string, mixed> what the script did */
    private function runScript(HotelTracking $tracking, array $scenario): array
    {
        $node = Process::run('node --version');

        if (! $node->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $file = tempnam(sys_get_temp_dir(), 'cvscript').'.js';
        file_put_contents($file, TrackingScript::build($tracking->load('hotel')));
        $result = Process::run(['node', base_path('tests/Fixtures/tracking-harness.cjs'), $file, json_encode($scenario)]);
        @unlink($file);

        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true);
    }

    public function test_an_ai_referrer_sends_a_beacon_and_a_ga4_event_and_other_referrers_send_nothing(): void
    {
        [, , $tracking] = $this->privateHotel();

        $chatgpt = $this->runScript($tracking, ['referrer' => 'https://chatgpt.com/', 'path' => '/rooms/deluxe', 'gtag' => true, 'search' => '?x=1']);
        $this->assertFalse($chatgpt['threw']);
        $this->assertCount(1, $chatgpt['beacons']);
        $this->assertEquals(['k' => $tracking->public_key, 't' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/rooms/deluxe'], $chatgpt['beacons'][0]['body']);
        $this->assertSame('text/plain', $chatgpt['beacons'][0]['type'], 'a simple request: no CORS preflight');
        $this->assertSame([['event', 'ai_referral', ['ai_platform' => 'ChatGPT', 'page_path' => '/rooms/deluxe']]], $chatgpt['gtag']);

        $utm = $this->runScript($tracking, ['referrer' => '', 'search' => '?utm_source=perplexity.ai&utm_medium=x', 'dataLayer' => true]);
        $this->assertSame('Perplexity', $utm['beacons'][0]['body']['p']);
        $this->assertSame('ai_referral', $utm['dataLayer'][0]['event'], 'without gtag the dataLayer gets the event');

        $word = $this->runScript($tracking, ['referrer' => '', 'search' => '?utm_source=ChatGPT']);
        $this->assertSame('ChatGPT', $word['beacons'][0]['body']['p'], 'a utm_source word is matched without case');

        $subdomain = $this->runScript($tracking, ['referrer' => 'https://www.chatgpt.com/c/123']);
        $this->assertSame('ChatGPT', $subdomain['beacons'][0]['body']['p']);

        foreach (['https://www.google.com/search?q=hotel', 'https://notchatgpt.com/', ''] as $referrer) {
            $this->assertSame([], $this->runScript($tracking, ['referrer' => $referrer, 'gtag' => true])['beacons'], "not an AI referrer: {$referrer}");
        }
        $this->assertSame([], $this->runScript($tracking, ['referrer' => 'https://google.com', 'search' => '?utm_source=newsletter'])['beacons']);
    }

    public function test_a_booking_click_reads_dates_and_guests_from_the_link_or_the_page_inputs(): void
    {
        [, , $tracking] = $this->privateHotel();

        $link = $this->runScript($tracking, ['click' => ['href' => 'https://book.nandini-hotel.com/search?checkin=2026-12-24&checkout=2026-12-27&adults=2&children=1&secret=abc', 'mustInclude' => 'data-booking'], 'gtag' => true]);
        $this->assertFalse($link['threw']);
        $this->assertEquals(['k' => $tracking->public_key, 't' => 'booking_click', 'path' => '/rooms', 'ci' => '2026-12-24', 'co' => '2026-12-27', 'a' => 2, 'c' => 1], $link['beacons'][0]['body'], 'only the dates and guests are sent');
        $this->assertSame('booking_click', $link['gtag'][0][1]);
        $this->assertSame(['page_path' => '/rooms', 'check_in_date' => '2026-12-24', 'check_out_date' => '2026-12-27', 'nights' => 3, 'adults' => 2, 'children' => 1], $link['gtag'][0][2]);

        $inputs = $this->runScript($tracking, ['click' => ['href' => '/book', 'mustInclude' => 'book.nandini-hotel.com'], 'inputs' => ['checkin' => '24/12/2026', 'checkout' => '27/12/2026', 'adult' => '3', 'child' => '0']]);
        $this->assertSame(['ci' => '2026-12-24', 'co' => '2026-12-27', 'a' => 3, 'c' => 0], array_intersect_key($inputs['beacons'][0]['body'], array_flip(['ci', 'co', 'a', 'c'])), 'day first by default');

        $mdy = $this->runScript($tracking, ['click' => ['href' => '/book', 'mustInclude' => 'data-booking'], 'inputs' => ['checkin' => '12/24/2026']]);
        $this->assertArrayNotHasKey('ci', $mdy['beacons'][0]['body'], '24 is not a month when the format is day first... read as 12 Dec? no: 12/24 is invalid day-first');

        $tracking->update(['settings' => ['date_format' => 'mdy']]);
        $american = $this->runScript($tracking, ['click' => ['href' => '/book', 'mustInclude' => 'data-booking'], 'inputs' => ['checkin' => '12/24/2026']]);
        $this->assertSame('2026-12-24', $american['beacons'][0]['body']['ci']);

        $junk = $this->runScript($tracking, ['click' => ['href' => '/book?checkin=31-02-2026&checkout=soon&adults=99', 'mustInclude' => 'data-booking']]);
        $this->assertEquals(['k' => $tracking->public_key, 't' => 'booking_click', 'path' => '/rooms'], $junk['beacons'][0]['body'], 'impossible dates and guest counts are left out');

        $this->assertSame([], $this->runScript($tracking, ['click' => ['href' => '/about', 'match' => false]])['beacons'], 'a click on something that is not a booking link sends nothing');
    }

    public function test_the_script_sets_no_cookie_uses_no_storage_and_never_breaks_the_page(): void
    {
        [, , $tracking] = $this->privateHotel();

        $busy = $this->runScript($tracking, ['referrer' => 'https://chatgpt.com/', 'gtag' => true, 'click' => ['href' => '/b?checkin=2026-12-24', 'mustInclude' => 'data-booking'], 'search' => '?citevanta_debug=1']);
        $this->assertFalse($busy['cookieTouched'], 'document.cookie is never read or written');
        $this->assertFalse($busy['storageTouched'], 'no local storage, session storage or IndexedDB');
        $this->assertFalse($busy['canvasTouched'], 'no canvas, no fingerprinting');
        $this->assertFalse($busy['threw']);

        $broken = $this->runScript($tracking, ['click' => ['href' => '/b', 'mustInclude' => 'data-booking'], 'breakQuerySelector' => true, 'inputs' => ['checkin' => '1']]);
        $this->assertFalse($broken['threw'], 'a failing page API never reaches the visitor');
        $this->assertCount(1, $broken['beacons']);

        $fallback = $this->runScript($tracking, ['referrer' => 'https://chatgpt.com/', 'noBeacon' => true]);
        $this->assertSame('omit', $fallback['fetches'][0]['credentials'], 'without sendBeacon a fetch without credentials is used');
    }

    public function test_the_debug_mode_shows_what_the_script_detects_only_when_asked(): void
    {
        [, , $tracking] = $this->privateHotel();

        $on = $this->runScript($tracking, ['referrer' => 'https://chatgpt.com/', 'search' => '?citevanta_debug=1']);
        $this->assertContains('CiteVanta debug', $on['debug']);
        $this->assertContains('ai_referral: ChatGPT', $on['debug']);

        $this->assertSame([], $this->runScript($tracking, ['referrer' => 'https://chatgpt.com/'])['debug'], 'nothing is drawn on the page without the debug parameter');
    }

    // --- the endpoint -------------------------------------------------------------------------

    public function test_a_valid_ai_referral_is_stored_with_the_path_only_and_sets_no_cookie(): void
    {
        [, $hotel, $tracking] = $this->privateHotel();

        $response = $this->collect(['k' => $tracking->public_key, 't' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/rooms/deluxe?utm_source=chatgpt.com&email=a@b.c#top']);

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'), 'exactly the hotel\'s own origin');
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $event = TrackingEvent::sole();
        $this->assertSame([$hotel->id, 'ai_referral', 'ChatGPT', '/rooms/deluxe'], [$event->hotel_id, $event->type, $event->platform, $event->path], 'the query string and fragment are dropped');
        $this->assertTrue($event->occurred_at->eq(now()));
        $this->assertNotNull($tracking->fresh()->last_seen_at);
    }

    public function test_only_the_hotels_own_website_is_allowed_through_cors(): void
    {
        [, , $tracking] = $this->privateHotel();
        $payload = ['k' => $tracking->public_key, 't' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/'];

        foreach (['https://nandini-hotel.com', 'https://www.nandini-hotel.com', 'https://rooms.nandini-hotel.com', 'http://www.nandini-hotel.com'] as $origin) {
            $this->collect($payload, $origin)->assertNoContent();
        }
        $this->assertSame(4, TrackingEvent::count());

        foreach (['https://evil.example', 'https://nandini-hotel.com.evil.example', 'https://notnandini-hotel.com', 'null'] as $origin) {
            $response = $this->collect($payload, $origin)->assertForbidden();
            $this->assertNull($response->headers->get('Access-Control-Allow-Origin'), "no CORS for {$origin}");
        }
        $this->collect($payload, null)->assertForbidden();
        $this->assertSame(4, TrackingEvent::count(), 'nothing was stored from the wrong sites');
    }

    public function test_the_public_key_and_the_hotels_state_are_checked(): void
    {
        [, , $tracking] = $this->privateHotel();
        $payload = ['t' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/'];

        $this->collect($payload + ['k' => 'cvk_aaaaaaaaaaaaaaaaaaaaaaaa'])->assertNotFound();
        $this->collect($payload + ['k' => 'not a key'])->assertNotFound();
        $this->collect($payload)->assertNotFound();

        $tracking->update(['enabled' => false]);
        $this->collect($payload + ['k' => $tracking->public_key])->assertNotFound();
        $tracking->update(['enabled' => true]);

        $tracking->hotel->team->update(['plan' => Plan::Starter]);
        $this->collect($payload + ['k' => $tracking->public_key])->assertNotFound();

        $this->assertSame(0, TrackingEvent::count());
    }

    public function test_the_payload_is_validated_and_bad_requests_get_a_status_code_not_an_error_page(): void
    {
        [, , $tracking] = $this->privateHotel();
        $k = $tracking->public_key;

        $this->call('POST', route('tracking.collect'), [], [], [], ['HTTP_ORIGIN' => self::ORIGIN], 'not json')->assertStatus(400);
        $this->call('POST', route('tracking.collect'), [], [], [], ['HTTP_ORIGIN' => self::ORIGIN], '"just a string"')->assertStatus(400);
        $this->call('POST', route('tracking.collect'), [], [], [], ['HTTP_ORIGIN' => self::ORIGIN], json_encode(['k' => $k, 'pad' => str_repeat('x', 3000)]))->assertStatus(413);

        $bad = [
            ['t' => 'ai_referral', 'p' => 'NotARealAssistant', 'path' => '/'],
            ['t' => 'ai_referral', 'p' => ['ChatGPT'], 'path' => '/'],
            ['t' => 'ai_referral', 'p' => 'ChatGPT'],
            ['t' => 'ai_referral', 'p' => 'ChatGPT', 'path' => 'rooms'],
            ['t' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/a b'],
            ['t' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/'.str_repeat('a', 400)],
            ['t' => 'pageview', 'path' => '/'],
            ['p' => 'ChatGPT', 'path' => '/'],
        ];
        foreach ($bad as $payload) {
            $this->collect($payload + ['k' => $k])->assertStatus(422);
        }

        $this->assertSame(0, TrackingEvent::count());
    }

    public function test_a_booking_click_keeps_valid_dates_and_guests_and_drops_the_rest(): void
    {
        [, , $tracking] = $this->privateHotel();
        $k = $tracking->public_key;

        $this->collect(['k' => $k, 't' => 'booking_click', 'path' => '/offers', 'ci' => '2026-12-24', 'co' => '2026-12-27', 'a' => 2, 'c' => 1])->assertNoContent();
        $good = TrackingEvent::sole();
        $this->assertSame(['2026-12-24', '2026-12-27', 3, 2, 1], [$good->checkin->toDateString(), $good->checkout->toDateString(), $good->nights, $good->adults, $good->children]);

        TrackingEvent::query()->delete();
        $cases = [
            'past check-in' => [['ci' => '2026-01-01', 'co' => '2026-01-05'], [null, null, null]],
            'too far ahead' => [['ci' => '2031-01-01'], [null, null, null]],
            'impossible date' => [['ci' => '2026-02-30'], [null, null, null]],
            'check-out before check-in' => [['ci' => '2026-12-24', 'co' => '2026-12-20'], ['2026-12-24', null, null]],
            'a 120 night stay' => [['ci' => '2026-12-24', 'co' => '2027-04-24'], ['2026-12-24', null, null]],
            'check-out without check-in' => [['co' => '2026-12-27'], [null, null, null]],
            'text, not a date' => [['ci' => 'tomorrow'], [null, null, null]],
        ];
        foreach ($cases as $label => [$fields, [$ci, $co, $nights]]) {
            $this->collect(['k' => $k, 't' => 'booking_click', 'path' => '/x'] + $fields)->assertNoContent();
            $event = TrackingEvent::query()->latest('id')->first();
            $this->assertSame([$ci, $co, $nights], [$event->checkin?->toDateString(), $event->checkout?->toDateString(), $event->nights], $label);
        }

        TrackingEvent::query()->delete();
        $this->collect(['k' => $k, 't' => 'booking_click', 'path' => '/x', 'a' => 0, 'c' => 25])->assertNoContent();
        $guests = TrackingEvent::sole();
        $this->assertSame([null, null], [$guests->adults, $guests->children], '0 adults and 25 children are not accepted');
    }

    public function test_preflight_requests_are_not_allowed_and_the_endpoint_is_rate_limited_per_key_not_per_ip(): void
    {
        [, , $tracking] = $this->privateHotel();
        [, , $other] = $this->privateHotel();
        config(['citevanta.tracking.rate_per_minute' => 3]);

        $preflight = $this->call('OPTIONS', route('tracking.collect'), [], [], [], ['HTTP_ORIGIN' => self::ORIGIN]);
        $preflight->assertNoContent();
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'), 'no CORS for a preflight');

        $payload = fn (HotelTracking $t) => ['k' => $t->public_key, 't' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/'];
        foreach (range(1, 3) as $i) {
            $this->collect($payload($tracking))->assertNoContent();
        }
        $this->collect($payload($tracking))->assertStatus(429);
        $this->collect($payload($tracking), server: ['REMOTE_ADDR' => '198.51.100.9'])->assertStatus(429, 'a different address does not get around it: the limit is per key');
        $this->collect($payload($other))->assertNoContent();
    }

    // --- the no-IP promise -----------------------------------------------------------------------

    public function test_no_ip_address_or_user_agent_is_stored_anywhere(): void
    {
        [, , $tracking] = $this->privateHotel();

        $this->collect(['k' => $tracking->public_key, 't' => 'ai_referral', 'p' => 'ChatGPT', 'path' => '/'])->assertNoContent();
        $this->collect(['k' => $tracking->public_key, 't' => 'booking_click', 'path' => '/b', 'ci' => '2026-12-24'])->assertNoContent();

        $everything = json_encode([DB::table('tracking_events')->get(), DB::table('hotel_tracking')->get()]);
        foreach (['203.0.113.77', 'SecretAgent', '9.9'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything, "{$secret} is not in the database");
        }

        foreach (['tracking_events', 'hotel_tracking'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/ip|agent|ua$|fingerprint|cookie|session|user_id|email/i', $column, "{$table}.{$column}");
            }
        }

        $cache = new ReflectionProperty(Cache::store('array')->getStore(), 'storage');
        $this->assertStringNotContainsString('203.0.113.77', json_encode($cache->getValue(Cache::store('array')->getStore())), 'the rate limiter does not keep the address either');
    }

    public function test_the_code_that_handles_the_script_never_reads_the_ip_or_the_user_agent(): void
    {
        $files = ['app/Http/Controllers/TrackingController.php', 'app/Services/Tracking/TrackingCollector.php', 'app/Services/Tracking/TrackingScript.php', 'app/Models/TrackingEvent.php'];

        foreach ($files as $file) {
            $code = (string) file_get_contents(base_path($file));
            foreach (['->ip(', '->ips(', 'userAgent', 'user_agent', 'REMOTE_ADDR', 'X-Forwarded', 'getClientIp', 'fingerprint('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file} must not use {$forbidden}");
            }
        }

        $limiter = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("'tracking:'", $limiter);
        $this->assertDoesNotMatchRegularExpression("/RateLimiter::for\\('tracking'.*?->ip\\(\\)/s", $limiter);
    }

    // --- Settings ------------------------------------------------------------------------------------

    public function test_the_owner_sees_the_snippet_the_settings_and_the_privacy_sentence(): void
    {
        [$owner, $hotel, $tracking] = $this->privateHotel();

        $this->actingAs($owner)->get(route('settings.website-script'))->assertOk()
            ->assertSee('<script async src="'.route('tracking.script', $tracking->public_key).'"></script>')
            ->assertSee('?citevanta_debug=1')->assertSee('Check installation')->assertSee('Copy the line')
            ->assertSee('sets no cookies, stores nothing in your browser, does not use your IP address')->assertSee($hotel->name)
            ->assertSee('Website script');
    }

    public function test_only_private_owners_can_open_the_settings(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        $member = User::factory()->create();
        $owner->currentTeam->addMember($member, TeamRole::Member);
        $member->switchTeam($owner->currentTeam);
        $starter = User::factory()->withTeam(Team::factory()->create(['plan' => Plan::Starter]))->create();
        Hotel::factory()->for($starter->currentTeam)->create(['setup_completed_at' => now()]);

        $this->actingAs($member)->get(route('settings.website-script'))->assertForbidden();
        $this->actingAs($starter)->get(route('settings.website-script'))->assertForbidden();
        $items = collect(app(SidebarMenu::class)->sections($owner)['Navigation'])->firstWhere('key', 'settings')->children ?? [];
        $this->assertContains('settings.website-script', collect($items)->pluck('key')->all());
    }

    public function test_saving_the_settings_changes_the_script_and_bad_input_is_rejected(): void
    {
        [$owner, , $tracking] = $this->privateHotel();

        Livewire::actingAs($owner)->test(WebsiteScript::class)
            ->set('selectors', "a.reserve\nbutton[data-book]")
            ->set('params.checkin', 'arrive, from_date')
            ->set('inputs.checkout', 'input#leave')
            ->set('dateFormat', 'mdy')
            ->call('save')->assertHasNoErrors()->assertSet('notice', 'Saved. The script updates itself within 5 minutes; nothing needs to change on your website.');

        $js = $this->get(route('tracking.script', $tracking->public_key))->getContent();
        foreach (['a.reserve', 'button[data-book]', '"checkin":["arrive","from_date"]', 'input#leave', '"df":"mdy"'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertStringContainsString('[data-booking]', json_encode(TrackingScript::settings(HotelTracking::forHotel($tracking->hotel))['selectors']) === '[]' ? '[data-booking]' : '[data-booking]');

        Livewire::actingAs($owner)->test(WebsiteScript::class)
            ->set('selectors', 'a { color: red }')->call('save')->assertHasErrors(['selectors'])
            ->set('selectors', "a\nb\nc\nd\ne\nf\ng\nh\ni\nj\nk")->call('save')->assertHasErrors(['selectors'])
            ->set('selectors', '')->set('params.checkin', 'bad name!')->call('save')->assertHasErrors(['params.checkin'])
            ->set('params.checkin', '')->set('inputs.adults', '<script>')->call('save')->assertHasErrors(['inputs.adults'])
            ->set('inputs.adults', '')->set('dateFormat', 'ymd')->call('save')->assertHasErrors(['dateFormat']);

        Livewire::actingAs($owner)->test(WebsiteScript::class)->set('enabled', false)->call('save');
        $this->get(route('tracking.script', $tracking->public_key))->assertNotFound();
    }

    // --- Check installation ----------------------------------------------------------------------------

    private function bindResolver(array $map): void
    {
        $this->app->instance(SafeSiteFetcher::class, new SafeSiteFetcher(fn (string $host) => $map[$host] ?? []));
    }

    public function test_check_installation_finds_the_script_on_the_home_page(): void
    {
        [$owner, , $tracking] = $this->privateHotel();
        $this->bindResolver(['www.nandini-hotel.com' => ['93.184.216.34']]);
        Http::fake(['https://www.nandini-hotel.com*' => Http::response('<html><head><script async src="https://app.example/t/'.$tracking->public_key.'.js"></script></head></html>')]);

        Livewire::actingAs($owner)->test(WebsiteScript::class)->call('checkInstallation')->assertSet('checkResult.installed', true)->assertSee('The script was found on nandini-hotel.com.');

        $this->assertTrue($tracking->fresh()->installed);
        $this->assertNotNull($tracking->fresh()->last_checked_at);
    }

    public function test_check_installation_says_what_is_wrong(): void
    {
        [$owner, , $tracking] = $this->privateHotel();
        $this->bindResolver(['www.nandini-hotel.com' => ['93.184.216.34']]);
        $status = 200;
        Http::fake(function () use (&$status) {
            return Http::response($status === 200 ? '<html><head></head></html>' : 'nope', $status);
        });

        Livewire::actingAs($owner)->test(WebsiteScript::class)->call('checkInstallation')->assertSet('checkResult.installed', false)->assertSee('did not find the script');

        $status = 503;
        Livewire::actingAs($owner)->test(WebsiteScript::class)->call('checkInstallation')->assertSee('the website answered with status 503');

        $this->bindResolver([]);
        Livewire::actingAs($owner)->test(WebsiteScript::class)->call('checkInstallation')->assertSee('the website address could not be found');
        $this->assertFalse($tracking->fresh()->installed);
    }

    // --- SSRF -------------------------------------------------------------------------------------------

    public function test_the_fetcher_never_connects_to_private_or_unusual_addresses(): void
    {
        $fetcher = new SafeSiteFetcher(fn (string $host) => match ($host) {
            'internal.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '192.168.1.10'], 'public.example' => ['93.184.216.34'], default => [],
        });
        Http::fake(['*' => Http::response('hello')]);

        foreach ([
            'http://127.0.0.1/', 'http://127.0.0.1:8080/', 'http://10.1.2.3/', 'http://172.16.0.9/', 'http://192.168.0.1/', 'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/', 'http://0.0.0.0/', 'http://[::1]/', 'http://[fd00::1]/', 'http://[fe80::1]/', 'http://localhost/', 'http://app.localhost/', 'http://printer.local/',
            'http://metadata.internal/', 'http://internal.example/', 'https://mixed.example/', 'file:///etc/passwd', 'gopher://public.example/', 'ftp://public.example/',
            'http://user:pass@public.example/', 'http://public.example:8080/', 'http://public.example:22/', 'javascript:alert(1)', 'not a url', '',
        ] as $url) {
            $result = $fetcher->fetch($url);
            $this->assertFalse($result['ok'], $url);
            $this->assertNotNull($result['error'], $url);
        }

        Http::assertNothingSent();
        $this->assertTrue($fetcher->fetch('https://public.example/')['ok']);
        $this->assertTrue(SafeSiteFetcher::isPublic('93.184.216.34'));
        $this->assertTrue(SafeSiteFetcher::isPublic('2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertFalse(SafeSiteFetcher::isPublic('::ffff:10.0.0.1'));
    }

    public function test_every_redirect_is_checked_again_and_the_number_of_hops_is_limited(): void
    {
        $fetcher = new SafeSiteFetcher(fn (string $host) => ['evil.example' => ['10.0.0.9']][$host] ?? ['93.184.216.34']);

        Http::fake([
            'https://start.example/to-internal' => Http::response('', 302, ['Location' => 'http://evil.example/secret']),
            'https://start.example/to-metadata' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest']),
            'https://start.example/relative' => Http::response('', 302, ['Location' => '/final']),
            'https://start.example/final' => Http::response('arrived'),
            'https://start.example/loop' => Http::response('', 302, ['Location' => '/loop']),
        ]);

        $this->assertSame('this address is not a public website', $fetcher->fetch('https://start.example/to-internal')['error']);
        $this->assertSame('this address is not a public website', $fetcher->fetch('https://start.example/to-metadata')['error']);
        $this->assertSame('arrived', $fetcher->fetch('https://start.example/relative')['body']);
        $this->assertSame('too many redirects', $fetcher->fetch('https://start.example/loop')['error']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example') || str_contains($request->url(), '169.254'));
    }

    public function test_the_page_is_cut_at_a_safe_size(): void
    {
        $fetcher = new SafeSiteFetcher(fn () => ['93.184.216.34']);
        Http::fake(['*' => Http::response(str_repeat('a', 2_000_000))]);

        $this->assertSame(1_500_000, strlen($fetcher->fetch('https://big.example/')['body']));
    }

    // --- the report --------------------------------------------------------------------------------------

    private function event(Hotel $hotel, string $type, string $when, ?string $platform = null): void
    {
        TrackingEvent::query()->create(['hotel_id' => $hotel->id, 'type' => $type, 'platform' => $platform, 'path' => '/', 'occurred_at' => $when]);
    }

    public function test_the_ai_report_shows_what_the_script_saw_with_comparison_and_csv(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/1', 'first_synced_at' => now()->subWeek()]);
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-10-01', 'row_key' => 'a', 'source' => 'chatgpt.com', 'sessions' => 4]);
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'b', 'source' => 'chatgpt.com', 'sessions' => 4]);
        foreach ([['2026-11-10 12:00:00', 'ChatGPT'], ['2026-11-11 12:00:00', 'ChatGPT'], ['2026-11-12 12:00:00', 'Perplexity'], ['2026-11-01 12:00:00', 'ChatGPT']] as [$when, $platform]) {
            $this->event($hotel, TrackingEvent::AI_REFERRAL, $when, $platform);
        }
        $this->event($hotel, TrackingEvent::BOOKING_CLICK, '2026-11-13 12:00:00');
        $this->event($hotel, TrackingEvent::AI_REFERRAL, '2026-11-10 12:00:00', 'Claude'); // another hotel's
        TrackingEvent::query()->latest('id')->first()->update(['hotel_id' => Hotel::factory()->create()->id]);

        $report = new AiTrafficReport($hotel, ReportPeriod::resolve(['from' => '2026-11-04', 'to' => '2026-11-23', 'compare' => 'previous'], Carbon::parse('2026-10-01')));
        $seen = $report->scriptSeen();
        $this->assertSame([3, 1, 1, 0], [$seen['ai'], $seen['clicks'], $seen['prev_ai'], $seen['prev_clicks']]);

        $table = $report->tables(new TableOptions)['ai-script'];
        $this->assertSame(['ChatGPT', 'Perplexity'], array_column($table->rows, 'name'));
        $this->assertSame([2, 1], array_column($table->rows, 'visits'));
        $this->assertEqualsWithDelta(1.0, $table->rows[0]['change'], 0.0001);
        $this->assertSame(1, $table->rows[0]['prev_visits']);
        $this->assertSame(['AI platform', 'Visits seen by the script', 'Previous visits', 'Change'], $table->csvHeader());

        $page = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'from' => '2026-11-04', 'to' => '2026-11-23', 'compare' => 'previous']))->assertOk();
        $page->assertSee('Seen by the website script:')->assertSee('3 visits from AI assistants')->assertSee('1 booking clicks')->assertSee('Seen by the website script', false);

        $csv = $this->actingAs($owner)->get(route('hotels.reports.export', [$hotel, 'ai-script', 'from' => '2026-11-04', 'to' => '2026-11-23', 'compare' => 'none']))->streamedContent();
        $this->assertStringContainsString('ChatGPT,2', $csv);
    }

    public function test_the_report_points_to_the_setup_when_the_script_is_not_set_up(): void
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['setup_completed_at' => now()]);
        HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'ga4_property' => 'properties/1', 'first_synced_at' => now()->subWeek()]);
        DB::table('ga4_traffic_daily')->insert(['hotel_id' => $hotel->id, 'date' => '2026-11-10', 'row_key' => 'b', 'source' => 'chatgpt.com', 'sessions' => 4]);

        $page = $this->actingAs($owner)->get(route('hotels.reports', [$hotel, 'compare' => 'none']))->assertOk();

        $page->assertSee('Add the website script to also count AI visits')->assertDontSee('Seen by the website script:');
        $this->assertNull((new AiTrafficReport($hotel, ReportPeriod::resolve(['range' => '28d'])))->scriptSeen());
    }

    // --- retention -------------------------------------------------------------------------------------------

    public function test_events_are_pruned_after_13_months_by_the_housekeeping_job(): void
    {
        [, $hotel] = $this->privateHotel();
        $this->event($hotel, TrackingEvent::AI_REFERRAL, now()->subMonths(14)->toDateTimeString(), 'ChatGPT');
        $this->event($hotel, TrackingEvent::AI_REFERRAL, now()->subMonths(12)->toDateTimeString(), 'ChatGPT');
        $this->event($hotel, TrackingEvent::BOOKING_CLICK, now()->subDay()->toDateTimeString());

        $this->artisan(HousekeepingCommand::class)->assertSuccessful();

        $this->assertSame(2, TrackingEvent::count());
        $this->assertSame(0, TrackingEvent::where('occurred_at', '<', now()->subMonths(13))->count());
        $this->assertSame(13, config('citevanta.tracking.retention_months'));
    }
}
