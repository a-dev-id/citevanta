<?php

namespace Tests\Feature\Private;

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\GoogleSyncJob;
use App\Livewire\Settings\ConnectGoogle;
use App\Mail\GoogleConnectionBroken;
use App\Models\GoogleConnection;
use App\Models\Hotel;
use App\Models\HotelGoogleLink;
use App\Models\Team;
use App\Models\User;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHealth;
use App\Services\Google\GoogleLinks;
use App\Services\Google\GoogleSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Google through the agent account: OAuth and token storage, refresh and
 * failure alerts, matching properties and sites, sync jobs, health checks.
 */
class GoogleConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.google.agent_email' => 'agent@citevanta.test', 'citevanta.google.requests_per_minute' => 10000]);
    }

    /** @return array{0: User, 1: Hotel} */
    private function privateHotel(string $website = 'https://www.nandinibali.com'): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => $website, 'setup_completed_at' => now()]);

        return [$owner, $hotel];
    }

    private function useRealGoogle(): void
    {
        config(['services.google.fake' => false, 'services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
        Http::preventStrayRequests();
    }

    private function connected(array $overrides = []): GoogleConnection
    {
        return GoogleConnection::create($overrides + [
            'refresh_token' => 'refresh-1', 'access_token' => 'access-1', 'access_expires_at' => now()->addHour(),
            'status' => 'ok', 'connected_at' => now(),
        ]);
    }

    public function test_an_admin_connects_the_agent_once_with_offline_access_and_the_tokens_are_stored_encrypted(): void
    {
        $this->useRealGoogle();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires_in' => 3599, 'scope' => 'openid email', 'id_token' => self::idToken('Agent@Citevanta.test')])]);
        $admin = User::factory()->admin()->create();

        $redirect = $this->actingAs($admin)->get(route('admin.integrations.google.connect'))->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $redirect);
        $this->assertSame(['offline', 'consent', 'code', 'agent@citevanta.test', route('admin.integrations.google.callback')], [$query['access_type'], $query['prompt'], $query['response_type'], $query['login_hint'], $query['redirect_uri']]);
        $this->assertSame('openid email https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly', $query['scope']);

        $this->actingAs($admin)->get(route('admin.integrations.google.callback', ['code' => 'c', 'state' => 'wrong']))->assertSessionHas('error');
        $this->assertSame(0, GoogleConnection::count());

        // The state is single use: start again.
        $redirect = $this->actingAs($admin)->get(route('admin.integrations.google.connect'))->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->actingAs($admin)->get(route('admin.integrations.google.callback', ['code' => 'auth-code', 'state' => $query['state']]))->assertSessionHas('status');

        $connection = GoogleConnection::sole();
        $this->assertSame(['1//refresh', 'ok', 'agent@citevanta.test'], [$connection->refresh_token, $connection->status, $connection->email]);
        $this->assertStringNotContainsString('1//refresh', (string) DB::table('google_connections')->value('refresh_token'));
        Http::assertSent(fn ($request) => $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code' && $request['client_secret'] === 'client-secret');
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSeeInOrder(['Connected account', 'agent@citevanta.test'])->assertDontSee('1//refresh');

        $this->actingAs(User::factory()->withTeam()->create())->get(route('admin.integrations.google'))->assertForbidden();
    }

    /** A Google ID token (only the payload matters: the app reads it straight from the token endpoint). */
    private static function idToken(string $email, bool $verified = true): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']).'.'.$encode(['email' => $email, 'email_verified' => $verified, 'sub' => '123']).'.signature';
    }

    public function test_the_connection_is_refused_and_revoked_when_another_account_signs_in(): void
    {
        $this->useRealGoogle();
        $admin = User::factory()->admin()->create();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'ya29.a', 'refresh_token' => '1//wrong', 'expires_in' => 3599, 'id_token' => self::idToken('owner@gmail.com')])
                ->push(['access_token' => 'ya29.b', 'refresh_token' => '1//unverified', 'expires_in' => 3599, 'id_token' => self::idToken('agent@citevanta.test', verified: false)]),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);

        foreach (['Please sign in as agent@citevanta.test. You signed in as owner@gmail.com.', 'Please sign in as agent@citevanta.test. You signed in as agent@citevanta.test.'] as $error) {
            $redirect = $this->actingAs($admin)->get(route('admin.integrations.google.connect'))->headers->get('Location');
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
            $this->actingAs($admin)->get(route('admin.integrations.google.callback', ['code' => 'c', 'state' => $query['state']]))->assertSessionHas('error', $error);
        }

        $this->assertSame(0, GoogleConnection::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke') && $request['token'] === '1//wrong');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revoke') && $request['token'] === '1//unverified');
    }

    public function test_the_email_comes_from_userinfo_when_the_token_has_no_id_token(): void
    {
        $this->useRealGoogle();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.a', 'refresh_token' => '1//r', 'expires_in' => 3599]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'agent@citevanta.test', 'email_verified' => true]),
        ]);

        $tokens = app(GoogleApi::class)->exchangeCode('code');

        $this->assertSame(['agent@citevanta.test', true], [$tokens['email'], $tokens['email_verified']]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'userinfo') && $request->hasHeader('Authorization', 'Bearer ya29.a'));
    }

    public function test_the_first_ga4_sync_reads_13_months_and_later_syncs_the_last_days(): void
    {
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/1']);
        Bus::fake();

        app(GoogleSync::class)->schedule($link);
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC
            && $job->from === now()->subDay()->subMonthsNoOverflow(13)->toDateString() && $job->to === now()->subDay()->toDateString());

        Bus::fake();
        app(GoogleSync::class)->schedule($link->fresh());
        Bus::assertDispatched(GoogleSyncJob::class, fn ($job) => $job->type === GoogleSync::GA4_TRAFFIC && $job->from === now()->subDays(3)->toDateString());
    }

    public function test_an_expired_access_token_is_refreshed_and_a_refused_refresh_token_alerts_admins_once(): void
    {
        $this->useRealGoogle();
        User::factory()->admin()->create();
        $this->connected(['access_expires_at' => now()->subMinute()]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'ya29.new', 'expires_in' => 3599])
                ->push(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:nandinibali.com', 'permissionLevel' => 'siteRestrictedUser']]]),
        ]);

        $this->assertSame('sc-domain:nandinibali.com', app(GoogleApi::class)->sites()[0]['siteUrl']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'webmasters') && $request->hasHeader('Authorization', 'Bearer ya29.new'));

        GoogleConnection::sole()->update(['access_expires_at' => now()->subMinute()]);
        foreach (range(1, 2) as $i) {
            try {
                app(GoogleApi::class)->sites();
                $this->fail('a refused refresh token must stop the call');
            } catch (GoogleApiException) {
            }
        }

        $this->assertSame('error', GoogleConnection::sole()->status);
        Mail::assertQueued(GoogleConnectionBroken::class, 1);
        $this->actingAs(User::where('is_admin', true)->first())->get(route('admin.system'))->assertSee('The Google connection stopped working');
        $this->artisan('citevanta:google-sync')->expectsOutputToContain('Google is disconnected');
    }

    public function test_only_properties_and_sites_of_the_hotel_domain_are_offered(): void
    {
        $this->useRealGoogle();
        $this->connected();
        [, $hotel] = $this->privateHotel('nandinibali.com');
        Http::fake([
            'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => [['displayName' => 'Nandini', 'propertySummaries' => [
                ['property' => 'properties/111', 'displayName' => 'Nandini GA4'], ['property' => 'properties/222', 'displayName' => 'Other hotel'],
            ]]]]),
            'analyticsadmin.googleapis.com/v1beta/properties/111/dataStreams*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://www.nandinibali.com']]]]),
            'analyticsadmin.googleapis.com/v1beta/properties/222/dataStreams*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://otherhotel.com']]]]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [
                ['siteUrl' => 'sc-domain:nandinibali.com', 'permissionLevel' => 'siteRestrictedUser'],
                ['siteUrl' => 'https://otherhotel.com/', 'permissionLevel' => 'siteRestrictedUser'],
            ]]),
        ]);

        $result = app(GoogleLinks::class)->candidates($hotel);

        $this->assertSame(['properties/111'], array_column($result['properties'], 'property'));
        $this->assertSame(['sc-domain:nandinibali.com'], array_column($result['sites'], 'siteUrl'));
        $this->assertSame([], $result['errors']);
    }

    public function test_clear_errors_when_nothing_is_shared_yet(): void
    {
        [, $hotel] = $this->privateHotel('https://not-shared-hotel.example');
        Hotel::query()->whereKeyNot($hotel->id)->delete();
        $hotel->update(['website' => null]);

        $this->assertStringContainsString('Add your hotel website first', app(GoogleLinks::class)->candidates($hotel)['errors'][0]);

        $this->useRealGoogle();
        $this->connected();
        Http::fake([
            'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => []]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []]),
        ]);
        $hotel->update(['website' => 'https://nandinibali.com']);

        $errors = app(GoogleLinks::class)->candidates($hotel)['errors'];
        $this->assertSame("We can't see any GA4 property for nandinibali.com yet. Did you add agent@citevanta.test as Viewer in GA4 (Admin > Property access management)?", $errors[0]);
        $this->assertStringContainsString('as a Restricted user', $errors[1]);
    }

    public function test_the_owner_links_property_and_site_which_starts_the_health_check_and_the_first_sync(): void
    {
        config(['citevanta.google.gsc_first_sync_months' => 1, 'citevanta.google.ga4_first_sync_months' => 13]);
        [$owner, $hotel] = $this->privateHotel();
        $hotel->update(['booking_engine_domain' => 'book.nandinibali.com']);

        Livewire::actingAs($owner)->test(ConnectGoogle::class)
            ->call('check')
            ->assertSet('property', 'properties/'.(900000 + $hotel->id))
            ->assertSet('site', 'sc-domain:nandinibali.com')
            ->call('link')
            ->assertSee('Connected.');

        $link = HotelGoogleLink::sole();
        $this->assertSame(['IDR', 'Asia/Makassar'], [$link->ga4_currency, $link->ga4_timezone]);
        $this->assertNotNull($link->first_synced_at);
        $this->assertGreaterThan(0, DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->count());
        $this->assertGreaterThan(0, DB::table('ga4_events_daily')->where('hotel_id', $hotel->id)->where('event_name', 'purchase')->count());
        $this->assertSame(DB::table('gsc_daily')->where('hotel_id', $hotel->id)->distinct()->count('date'), (int) now()->subDay()->subMonthNoOverflow()->diffInDays(now()->subDay()) + 1, 'one Search Console job per day');

        $health = $link->fresh()->health;
        $this->assertSame(GoogleHealth::CHECKS, array_keys($health));
        $this->assertSame('green', $health['receiving']['status']);
        $this->assertSame('green', $health['purchases']['status']);
        $this->assertSame('green', $health['cross_domain']['status']);
        $this->assertSame('green', $health['ai_referrals']['status']);
        $this->assertStringContainsString('ChatGPT', $health['ai_referrals']['message']);
        $this->assertSame(['checkin' => 'checkin_date', 'checkout' => 'checkout_date', 'adults' => 'adults', 'children' => 'children', 'nights' => null, 'confirmed' => false], $link->fresh()->date_mapping);
    }

    public function test_daily_syncs_reread_recent_days_and_pages_are_further_jobs(): void
    {
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/'.(900000 + $hotel->id), 'gsc_site' => 'sc-domain:nandinibali.com', 'first_synced_at' => now()->subWeek()]);
        config(['citevanta.google.ga4_page_size' => 7]);

        $jobs = app(GoogleSync::class)->schedule($link);

        $this->assertSame(2 + 5, $jobs, 'GA4 traffic and events for the last 3 days, Search Console for 5 days');
        // 3 days x 5 sources = 15 traffic rows, fetched 7 at a time.
        $this->assertSame(15, DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->count());
        $this->assertSame(5, DB::table('gsc_daily')->where('hotel_id', $hotel->id)->distinct()->count('date'));

        app(GoogleSync::class)->schedule($link->fresh());
        $this->assertSame(15, DB::table('ga4_traffic_daily')->where('hotel_id', $hotel->id)->count(), 'a re-sync replaces rows, no duplicates');
    }

    public function test_quota_errors_are_retried_and_access_errors_are_shown(): void
    {
        $this->assertTrue((new GoogleApiException('x', 429, 'RESOURCE_EXHAUSTED'))->isQuota());
        $this->assertTrue((new GoogleApiException('x', 403, 'rateLimitExceeded'))->isQuota());
        $this->assertTrue((new GoogleApiException('x', 403, 'PERMISSION_DENIED'))->isPermission());

        $this->useRealGoogle();
        $this->connected();
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/111', 'first_synced_at' => now()]);
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.', 'status' => 'PERMISSION_DENIED']], 403)]);

        GoogleSyncJob::dispatchSync($link->id, GoogleSync::GA4_EVENTS, now()->subDays(3)->toDateString(), now()->subDay()->toDateString());

        $this->assertStringContainsString('sufficient permissions', (string) $link->fresh()->last_sync_error);
        Http::assertSent(fn ($request) => $request['returnPropertyQuota'] === true && $request['limit'] === '10000');
    }

    public function test_health_check_red_states_explain_what_to_send_to_the_web_developer(): void
    {
        $this->useRealGoogle();
        $this->connected();
        [, $hotel] = $this->privateHotel();
        $hotel->update(['booking_engine_domain' => 'secure.booking-engine.test']);
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/111', 'ga4_currency' => 'IDR']);
        Http::fake([
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push(['rows' => []])
                ->push(['rows' => [['metricValues' => [['value' => '3'], ['value' => '0']]]]])
                ->push(['rows' => [['dimensionValues' => [['value' => 'www.nandinibali.com']], 'metricValues' => [['value' => '40']]]]]),
            'analyticsadmin.googleapis.com/*' => Http::response(['customDimensions' => [['parameterName' => 'room_type', 'displayName' => 'Room', 'scope' => 'EVENT']]]),
        ]);
        $health = app(GoogleHealth::class);

        $this->assertSame('red', $health->run($link, 'receiving')['status']);
        $purchases = $health->run($link, 'purchases');
        $this->assertSame(['amber', '3 purchase events in the last 30 days, but without a booking value.'], [$purchases['status'], $purchases['message']]);
        $cross = $health->run($link, 'cross_domain');
        $this->assertSame('red', $cross['status']);
        $this->assertStringContainsString('Configure your domains, add both nandinibali.com and secure.booking-engine.test', $cross['developer']);
        $dates = $health->run($link, 'booking_dates');
        $this->assertSame('red', $dates['status']);
        $this->assertStringContainsString('Admin > Data display > Custom definitions > Create custom dimension', $dates['developer']);
        $this->assertStringContainsString('"Check-in date" with Event parameter "check_in_date"', $dates['developer']);
    }

    public function test_booking_date_parameters_are_detected_by_common_names(): void
    {
        $this->assertSame(
            ['checkin' => 'Arrival_Date', 'checkout' => 'departure_date', 'adults' => 'num_adults', 'children' => null, 'nights' => 'los'],
            GoogleHealth::detectMapping(['Arrival_Date', 'departure_date', 'num_adults', 'los', 'room']),
        );
    }

    public function test_the_owner_confirms_the_mapping_and_only_owners_of_the_team_can_use_the_page(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/'.(900000 + $hotel->id),
            'health' => ['booking_dates' => ['status' => 'green', 'message' => 'ok', 'developer' => null, 'details' => ['parameters' => ['checkin_date', 'checkout_date', 'stay']]]]]);

        Livewire::actingAs($owner)->test(ConnectGoogle::class)
            ->set('mapping.checkin', 'checkin_date')->set('mapping.checkout', 'checkout_date')->set('mapping.nights', 'stay')
            ->call('saveMapping');
        $this->assertSame(['checkin' => 'checkin_date', 'checkout' => 'checkout_date', 'adults' => null, 'children' => null, 'nights' => 'stay', 'confirmed' => true], $hotel->googleLink->fresh()->date_mapping);

        $member = User::factory()->create();
        $hotel->team->addMember($member, TeamRole::Member);
        $member->switchTeam($hotel->team);
        $this->actingAs($member)->get(route('settings.google'))->assertForbidden();
        $this->actingAs(User::factory()->withTeam()->create())->get(route('settings.google'))->assertForbidden();
        $this->actingAs($owner->fresh())->get(route('settings.google'))->assertOk()->assertSee('agent@citevanta.test')->assertSee('Property access management');
    }

    public function test_disconnect_removes_the_link_and_its_data_and_reminds_the_owner(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        [, $other] = $this->privateHotel('https://otherhotel.com');
        foreach ([$hotel, $other] as $h) {
            HotelGoogleLink::create(['hotel_id' => $h->id, 'team_id' => $h->team_id, 'ga4_property' => 'properties/1']);
            DB::table('ga4_events_daily')->insert(['hotel_id' => $h->id, 'date' => '2026-11-01', 'event_name' => 'purchase', 'event_count' => 1]);
        }

        Livewire::actingAs($owner)->test(ConnectGoogle::class)->call('disconnect')->assertSee('Please also remove agent@citevanta.test');

        $this->assertNull($hotel->googleLink()->first());
        $this->assertSame([$other->id], DB::table('ga4_events_daily')->pluck('hotel_id')->all(), 'other teams keep their data');
    }

    public function test_the_hotel_dashboard_shows_the_health_summary(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'health' => [
            'receiving' => ['status' => 'green'], 'purchases' => ['status' => 'red'], 'ai_referrals' => ['status' => 'amber'],
        ]]);

        $this->actingAs($owner)->get(route('hotels.show', $hotel))->assertSee('Tracking health: 1 OK')->assertSee('1 to fix');
    }
}
