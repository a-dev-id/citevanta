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
use App\Services\Google\FakeGoogleApi;
use App\Services\Google\GoogleAgent;
use App\Services\Google\GoogleApi;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHealth;
use App\Services\Google\GoogleLinks;
use App\Services\Google\GoogleServiceAccount;
use App\Services\Google\GoogleSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use ReflectionMethod;
use SensitiveParameter;
use Tests\TestCase;

/**
 * Google through a service account: the signed token request and its cache,
 * the e-mail taken from the key file, a missing, invalid or refused key, that
 * the key never leaks, fake data mode, and (unchanged) matching properties and
 * sites, sync jobs and health checks. Keys here are generated for the test and
 * thrown away; a real key file is never read.
 */
class GoogleConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'reader@test-project.iam.gserviceaccount.com';

    private static ?string $pem = null;

    /** @var list<string> */
    private array $folders = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['citevanta.google.requests_per_minute' => 10000]);
    }

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            File::deleteDirectory($folder);
        }

        parent::tearDown();
    }

    // --- helpers ------------------------------------------------------------

    private static function pem(): string
    {
        if (self::$pem === null) {
            $config = tempnam(sys_get_temp_dir(), 'ssl');
            file_put_contents($config, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
            openssl_pkey_export($key, $pem, null, ['config' => $config]);
            unlink($config);
            self::$pem = $pem;
        }

        return self::$pem;
    }

    private static function publicKey(): string
    {
        return openssl_pkey_get_details(openssl_pkey_get_private(self::pem()))['key'];
    }

    /**
     * Write a key file for this test and return its path.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function keyFile(array $overrides = []): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-google-test-'.uniqid();
        mkdir($folder, 0777, true);
        $this->folders[] = $folder;
        $path = $folder.DIRECTORY_SEPARATOR.'key.json';

        file_put_contents($path, json_encode($overrides + [
            'type' => 'service_account', 'project_id' => 'test-project', 'private_key_id' => 'abc123', 'private_key' => self::pem(),
            'client_email' => self::EMAIL, 'client_id' => '1', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        return $path;
    }

    private function rawKeyFile(string $contents): string
    {
        $path = $this->keyFile();
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Switch from fake data to the real Google client with a generated key.
     *
     * @param  bool  $tokenEndpoint  also fake a successful token endpoint (tests that need another answer pass false)
     */
    private function useRealGoogle(bool $tokenEndpoint = true, ?string $path = null): string
    {
        $path ??= $this->keyFile();
        config(['services.google.fake' => false, 'services.google.service_account_json_path' => $path]);
        Http::preventStrayRequests();

        if ($tokenEndpoint) {
            Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.sa', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);
        }

        return $path;
    }

    /** @return array{0: User, 1: Hotel} */
    private function privateHotel(string $website = 'https://www.nandinibali.com'): array
    {
        $team = Team::factory()->create(['plan' => Plan::Private]);
        $owner = User::factory()->withTeam($team)->create();
        $hotel = Hotel::factory()->for($team)->create(['website' => $website, 'setup_completed_at' => now()]);

        return [$owner, $hotel];
    }

    private function tokenRequests(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'))->count();
    }

    // --- the service account: token, cache, e-mail --------------------------

    public function test_the_token_request_is_a_signed_jwt_for_the_two_read_only_scopes(): void
    {
        $this->useRealGoogle();
        Http::fake(['www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:nandinibali.com', 'permissionLevel' => 'siteRestrictedUser']]])]);

        $this->assertSame('sc-domain:nandinibali.com', app(GoogleApi::class)->sites()[0]['siteUrl']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }

            $decode = fn (string $part) => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);
            [$header, $claims, $signature] = explode('.', (string) $request['assertion']);
            $claims = $decode($claims);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $decode($header) === ['alg' => 'RS256', 'typ' => 'JWT']
                && $claims['iss'] === self::EMAIL
                && $claims['scope'] === 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly'
                && $claims['aud'] === 'https://oauth2.googleapis.com/token'
                && $claims['exp'] - $claims['iat'] === 3600
                && openssl_verify($header.'.'.explode('.', (string) $request['assertion'])[1], (string) base64_decode(strtr($signature, '-_', '+/')), self::publicKey(), OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), 'webmasters/v3/sites') && $request->hasHeader('Authorization', 'Bearer ya29.sa'));
    }

    public function test_the_access_token_is_cached_for_about_50_minutes_and_kept_encrypted(): void
    {
        $this->useRealGoogle();
        Http::fake(['www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []])]);
        $api = app(GoogleApi::class);

        $api->sites();
        $api->sites();
        $api->sites();
        $this->assertSame(1, $this->tokenRequests());

        $cached = (string) Cache::get('google:service-account-token:'.substr(sha1(self::EMAIL.'|abc123'), 0, 16));
        $this->assertNotSame('', $cached);
        $this->assertStringNotContainsString('ya29.sa', $cached, 'the cached token is encrypted');

        $this->travel(49)->minutes();
        $api->sites();
        $this->assertSame(1, $this->tokenRequests(), 'still cached after 49 minutes');

        $this->travel(2)->minutes();
        $api->sites();
        $this->assertSame(2, $this->tokenRequests(), 'a new token after 50 minutes');
    }

    public function test_a_token_that_google_revoked_early_is_replaced_once(): void
    {
        $this->useRealGoogle(tokenEndpoint: false);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()->push(['access_token' => 'ya29.old', 'expires_in' => 3599])->push(['access_token' => 'ya29.new', 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites' => Http::sequence()->push(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)->push(['siteEntry' => []]),
        ]);

        $this->assertSame([], app(GoogleApi::class)->sites());

        $this->assertSame(2, $this->tokenRequests());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'webmasters') && $request->hasHeader('Authorization', 'Bearer ya29.new'));
    }

    public function test_the_service_account_email_comes_from_the_key_file_and_is_shown_with_a_copy_button(): void
    {
        $this->useRealGoogle();
        $admin = User::factory()->admin()->create();
        [$owner] = $this->privateHotel();

        $this->assertSame(self::EMAIL, app(GoogleServiceAccount::class)->email());
        $this->assertSame(self::EMAIL, app(GoogleAgent::class)->email());

        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk()
            ->assertSee('Service account email')->assertSee(self::EMAIL)->assertSee('Copy email')->assertSee('Found and valid');
        $this->actingAs($owner)->get(route('settings.google'))->assertOk()
            ->assertSee(self::EMAIL)->assertSee('Copy email')->assertSee('Property access management');

        config(['app.url' => 'https://other.example']);
        $this->assertSame(self::EMAIL, app(GoogleServiceAccount::class)->email(), 'the email never depends on .env values other than the key path');
    }

    public function test_a_path_relative_to_the_storage_folder_works(): void
    {
        $folder = storage_path('framework/testing/google-'.uniqid());
        mkdir($folder, 0777, true);
        $this->folders[] = $folder;
        copy($this->keyFile(), $folder.'/key.json');

        $this->useRealGoogle(path: 'framework/testing/'.basename($folder).'/key.json');

        $this->assertSame(self::EMAIL, app(GoogleServiceAccount::class)->email());
        $this->assertSame(str_replace('\\', '/', $folder).'/key.json', str_replace('\\', '/', app(GoogleServiceAccount::class)->path()));
    }

    // --- a missing, invalid or refused key ----------------------------------

    public function test_a_missing_key_file_is_reported_and_alerts_the_admins_once(): void
    {
        $this->useRealGoogle(path: sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-no-such-key.json');
        $admin = User::factory()->admin()->create();

        $status = app(GoogleServiceAccount::class)->status();
        $this->assertSame([true, false, false, null], [$status['configured'], $status['found'], $status['valid'], $status['email']]);

        foreach (range(1, 2) as $ignored) {
            try {
                app(GoogleApi::class)->sites();
                $this->fail('a missing key must stop the call');
            } catch (GoogleApiException $e) {
                $this->assertSame('key_missing', $e->reason);
                $this->assertTrue($e->isKeyProblem());
            }
        }

        Mail::assertQueued(GoogleConnectionBroken::class, 1);
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('Not found')->assertSee('The Google key file was not found')
            ->assertSee('Google refused the service account key');
        Http::assertNothingSent();
    }

    public function test_an_invalid_key_file_is_reported_without_showing_any_of_its_content(): void
    {
        $admin = User::factory()->admin()->create();
        $marker = 'TOP-SECRET-MARKER-91827';

        $cases = [
            'not json' => [$this->rawKeyFile("{$marker} this is not json"), 'not valid JSON'],
            'wrong type' => [$this->keyFile(['type' => 'authorized_user', 'client_secret' => $marker]), 'not a service account key'],
            'no email' => [$this->keyFile(['client_email' => 'nope', 'extra' => $marker]), 'not a service account key'],
            'unreadable key' => [$this->keyFile(['private_key' => "-----BEGIN PRIVATE KEY-----\n{$marker}\n-----END PRIVATE KEY-----\n"]), 'cannot be read'],
        ];

        foreach ($cases as $label => [$path, $expected]) {
            $this->useRealGoogle(path: $path);

            $status = app(GoogleServiceAccount::class)->status();
            $this->assertSame([true, true, false, null], [$status['configured'], $status['found'], $status['valid'], $status['email']], $label);
            $this->assertStringContainsString($expected, (string) $status['problem'], $label);

            $page = $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('Found, but not valid')->getContent();
            $this->assertStringNotContainsString($marker, $page, $label);
            $this->assertStringNotContainsString($marker, (string) json_encode($status), $label);
        }
    }

    public function test_a_key_refused_by_google_shows_the_banner_emails_admins_once_and_a_good_test_clears_it(): void
    {
        $this->useRealGoogle(tokenEndpoint: false);
        $admin = User::factory()->admin()->create();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
                ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
                ->push(['access_token' => 'ya29.fixed', 'expires_in' => 3599]),
            'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => [['displayName' => 'Nandini', 'propertySummaries' => [['property' => 'properties/111', 'displayName' => 'Nandini GA4']]]]]),
        ]);

        foreach (range(1, 2) as $ignored) {
            try {
                app(GoogleApi::class)->properties();
                $this->fail('a refused key must stop the call');
            } catch (GoogleApiException $e) {
                $this->assertSame('invalid_grant', $e->reason);
                $this->assertSame('Google refused the service account key: Invalid JWT Signature.', $e->getMessage());
            }
        }

        $connection = GoogleConnection::sole();
        $this->assertSame('error', $connection->status);
        $this->assertStringContainsString('Invalid JWT Signature.', (string) $connection->error);
        Mail::assertQueued(GoogleConnectionBroken::class, 1);
        $this->actingAs($admin)->get(route('admin.system'))->assertSee('Google refused the service account key');
        $this->artisan('citevanta:google-sync')->expectsOutputToContain('Google refused the service account key');
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('Google refused the key');

        $this->actingAs($admin)->post(route('admin.integrations.google.test'))
            ->assertSessionHas('status', 'Google accepted the key. 1 GA4 property is shared with '.self::EMAIL.' (1 account).');

        $connection->refresh();
        $this->assertSame(['ok', null, null], [$connection->status, $connection->error, $connection->failure_alerted_at]);
        $this->assertNotNull($connection->last_success_at);
        $this->actingAs($admin)->get(route('admin.system'))->assertDontSee('Google refused the service account key');
        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertSee('Working')->assertSee('Last successful call');
    }

    public function test_test_connection_reports_a_refusal_and_an_empty_account_and_is_for_admins_only(): void
    {
        $this->useRealGoogle(tokenEndpoint: false);
        $admin = User::factory()->admin()->create();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()->push(['error' => 'invalid_client', 'error_description' => 'The service account was deleted.'], 401)->push(['access_token' => 'ya29.a', 'expires_in' => 3599]),
            'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([]),
        ]);

        $this->actingAs($admin)->post(route('admin.integrations.google.test'))
            ->assertSessionHas('error', 'Google test failed: Google refused the service account key: The service account was deleted.');
        $this->actingAs($admin)->post(route('admin.integrations.google.test'))
            ->assertSessionHas('status', 'Google accepted the key. No GA4 property is shared with '.self::EMAIL.' yet.');

        $this->actingAs(User::factory()->withTeam()->create())->post(route('admin.integrations.google.test'))->assertForbidden();
    }

    public function test_the_old_oauth_routes_are_gone(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/integrations/google/connect')->assertNotFound();
        $this->actingAs($admin)->get('/admin/integrations/google/callback?code=x&state=y')->assertNotFound();
        $this->assertFalse(Route::has('admin.integrations.google.connect'));
        $this->assertFalse(Route::has('admin.integrations.google.callback'));
    }

    // --- the key never leaks ------------------------------------------------

    public function test_the_key_is_never_stored_shown_logged_or_put_in_a_message(): void
    {
        $this->useRealGoogle(tokenEndpoint: false);
        $admin = User::factory()->admin()->create();
        [$owner] = $this->privateHotel();
        $body = trim((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', self::pem()));
        $dump = fn (string $text) => str_contains($text, $body) || str_contains($text, 'BEGIN PRIVATE KEY') || str_contains($text, 'private_key');

        // Google echoing a key block back in its error text must not get through either.
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => "Bad key:\n".self::pem()."\nend"], 400)]);

        try {
            app(GoogleApi::class)->sites();
            $this->fail('a refused key must stop the call');
        } catch (GoogleApiException $e) {
            $this->assertFalse($dump($e->getMessage()));
            $this->assertStringContainsString('[private key removed]', $e->getMessage());
            $this->assertFalse($dump($e->getTraceAsString()), 'masked in stack traces too');
        }

        $tables = ['google_connections', 'hotel_google_links', 'cache', 'jobs', 'failed_jobs'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->assertFalse($dump(json_encode(DB::table($table)->get())), $table);
            }
        }

        foreach ([$this->actingAs($admin)->get(route('admin.integrations.google')), $this->actingAs($owner)->get(route('settings.google'))] as $response) {
            $this->assertFalse($dump($response->getContent()));
        }

        $this->assertFalse($dump(json_encode(app(GoogleServiceAccount::class)->status())));
        $this->assertFalse($dump((string) $this->app->make(GoogleAgent::class)->connection()?->error));
        $this->assertCount(1, (new ReflectionMethod(GoogleServiceAccount::class, 'signedJwt'))->getParameters()[0]->getAttributes(SensitiveParameter::class), 'the key array is hidden from stack traces');
    }

    // --- fake data mode (unchanged) -----------------------------------------

    public function test_with_an_empty_path_the_app_keeps_using_fake_data_and_never_calls_google(): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->admin()->create();

        $this->assertTrue((bool) config('services.google.fake'), 'fake data by default when no key path is set');
        $this->assertInstanceOf(FakeGoogleApi::class, app(GoogleApi::class));
        $this->assertSame(GoogleAgent::FAKE_EMAIL, app(GoogleAgent::class)->email());

        $this->actingAs($admin)->get(route('admin.integrations.google'))->assertOk()
            ->assertSee(GoogleAgent::FAKE_EMAIL)->assertSee('Not set (fake data)')->assertSee('Fake data');
        $this->actingAs($admin)->post(route('admin.integrations.google.test'))->assertSessionHas('status');

        Http::assertNothingSent();
    }

    public function test_the_real_client_is_used_as_soon_as_a_key_path_is_set(): void
    {
        config(['services.google.fake' => false]);

        $this->assertNotInstanceOf(FakeGoogleApi::class, app(GoogleApi::class));
    }

    public function test_an_old_oauth_row_is_taken_over_its_tokens_are_blanked_and_hotel_choices_stay(): void
    {
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/111', 'ga4_property_name' => 'Nandini GA4', 'gsc_site' => 'sc-domain:nandinibali.com']);
        GoogleConnection::create(['email' => 'old-agent@example.com', 'refresh_token' => 'old-refresh-token', 'access_token' => 'old-access', 'status' => 'error', 'error' => 'old', 'failure_alerted_at' => now()]);
        $this->useRealGoogle();
        Http::fake(['analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([])]);

        app(GoogleAgent::class)->testConnection(app(GoogleApi::class));

        $connection = GoogleConnection::sole();
        $this->assertSame(['', null, 'ok', self::EMAIL], [$connection->refresh_token, $connection->access_token, $connection->status, $connection->email]);
        $this->assertStringNotContainsString('old-refresh-token', json_encode(DB::table('google_connections')->get()));
        $this->assertSame(['properties/111', 'Nandini GA4', 'sc-domain:nandinibali.com'], [$link->fresh()->ga4_property, $link->fresh()->ga4_property_name, $link->fresh()->gsc_site]);
    }

    // --- hotel side, sync and health (unchanged behaviour) ------------------

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

    public function test_only_properties_and_sites_the_service_account_can_see_and_of_the_hotel_domain_are_offered(): void
    {
        $this->useRealGoogle();
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

    public function test_clear_messages_when_nothing_or_only_other_hotels_are_shared(): void
    {
        [, $hotel] = $this->privateHotel('https://not-shared-hotel.example');
        Hotel::query()->whereKeyNot($hotel->id)->delete();
        $hotel->update(['website' => null]);

        $this->assertStringContainsString('Add your hotel website first', app(GoogleLinks::class)->candidates($hotel)['errors'][0]);

        // Nothing at all is shared with the service account.
        $this->useRealGoogle();
        Http::fake([
            'analyticsadmin.googleapis.com/*' => Http::response(['accountSummaries' => []]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []]),
        ]);
        $hotel->update(['website' => 'https://nandinibali.com']);

        $result = app(GoogleLinks::class)->candidates($hotel);
        $this->assertSame(['Nothing is shared with '.self::EMAIL.' yet. Share your GA4 property (role Viewer) and your Search Console site (permission Restricted) with this email, then click Check connection again.'], $result['errors']);
        $this->assertSame([[], []], [$result['properties'], $result['sites']]);

    }

    public function test_clear_message_when_only_other_hotels_are_shared(): void
    {
        $this->useRealGoogle();
        [, $hotel] = $this->privateHotel('https://nandinibali.com');
        Http::fake([
            'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => [['displayName' => 'Other', 'propertySummaries' => [['property' => 'properties/222', 'displayName' => 'Other']]]]]),
            'analyticsadmin.googleapis.com/v1beta/properties/222/dataStreams*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://otherhotel.com']]]]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:otherhotel.com', 'permissionLevel' => 'siteRestrictedUser']]]),
        ]);

        $errors = app(GoogleLinks::class)->candidates($hotel)['errors'];

        $this->assertSame("We can't see any GA4 property for nandinibali.com yet. Did you add ".self::EMAIL.' as Viewer in GA4 (Admin > Property access management)?', $errors[0]);
        $this->assertStringContainsString('as a Restricted user', $errors[1]);
    }

    public function test_the_owner_sees_a_calm_message_when_the_key_is_broken(): void
    {
        $this->useRealGoogle(path: sys_get_temp_dir().DIRECTORY_SEPARATOR.'citevanta-no-such-key.json');
        [$owner] = $this->privateHotel();

        Livewire::actingAs($owner)->test(ConnectGoogle::class)->call('check')
            ->assertSee('The Google connection is not available right now. Please try again later; our team has been notified.')
            ->assertDontSee('citevanta-no-such-key');
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
        $this->assertTrue((new GoogleApiException('x', 400, 'invalid_grant'))->isKeyProblem());
        $this->assertFalse((new GoogleApiException('x', 403, 'PERMISSION_DENIED'))->isKeyProblem());

        $this->useRealGoogle();
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/111', 'first_synced_at' => now()]);
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.', 'status' => 'PERMISSION_DENIED']], 403)]);

        GoogleSyncJob::dispatchSync($link->id, GoogleSync::GA4_EVENTS, now()->subDays(3)->toDateString(), now()->subDay()->toDateString());

        $this->assertStringContainsString('sufficient permissions', (string) $link->fresh()->last_sync_error);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'analyticsdata') && $request['returnPropertyQuota'] === true && $request['limit'] === '10000');
    }

    public function test_health_check_red_states_explain_what_to_send_to_the_web_developer(): void
    {
        $this->useRealGoogle();
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

    public function test_when_access_is_lost_the_health_message_names_the_service_account(): void
    {
        $this->useRealGoogle();
        [, $hotel] = $this->privateHotel();
        $link = HotelGoogleLink::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->team_id, 'ga4_property' => 'properties/111']);
        Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'No access', 'status' => 'PERMISSION_DENIED']], 403)]);

        $result = app(GoogleHealth::class)->run($link, 'receiving');

        $this->assertSame('red', $result['status']);
        $this->assertSame('We lost access. Check that '.self::EMAIL.' still has access in GA4 and Search Console.', $result['message']);
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
        $this->actingAs($owner->fresh())->get(route('settings.google'))->assertOk()->assertSee(GoogleAgent::FAKE_EMAIL)->assertSee('Property access management')->assertSee('Restricted');
    }

    public function test_disconnect_removes_the_link_and_its_data_and_reminds_the_owner(): void
    {
        [$owner, $hotel] = $this->privateHotel();
        [, $other] = $this->privateHotel('https://otherhotel.com');
        foreach ([$hotel, $other] as $h) {
            HotelGoogleLink::create(['hotel_id' => $h->id, 'team_id' => $h->team_id, 'ga4_property' => 'properties/1']);
            DB::table('ga4_events_daily')->insert(['hotel_id' => $h->id, 'date' => '2026-11-01', 'event_name' => 'purchase', 'event_count' => 1]);
        }

        Livewire::actingAs($owner)->test(ConnectGoogle::class)->call('disconnect')->assertSee('Please also remove '.GoogleAgent::FAKE_EMAIL);

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
