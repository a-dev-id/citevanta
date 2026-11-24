<?php

namespace App\Services\Google;

use App\Mail\GoogleConnectionBroken;
use App\Models\GoogleConnection;
use App\Services\Budget\AdminMailer;
use Illuminate\Database\QueryException;

/**
 * The Google connection of the app: a service account (see GoogleServiceAccount)
 * that hotels share GA4 and Search Console with.
 *
 * Keeps the connection state in the google_connections row (the older OAuth
 * columns stay unused): status, the last error, the last successful call and
 * whether the admins were already told. When Google refuses the key the
 * connection is marked broken, a red banner shows, and the admins get one
 * email. The next successful call (for example "Test connection") clears it.
 */
final class GoogleAgent
{
    /** Shown in fake data mode (no key file): there is no real account. */
    public const FAKE_EMAIL = 'citevanta-reader@sample-project.iam.gserviceaccount.com';

    public function __construct(private readonly AdminMailer $admins, private readonly GoogleServiceAccount $account) {}

    /**
     * The service account e-mail that hotels add in GA4 and Search Console.
     */
    public function email(): ?string
    {
        return config('services.google.fake') ? self::FAKE_EMAIL : $this->account->email();
    }

    public function account(): GoogleServiceAccount
    {
        return $this->account;
    }

    public function connection(): ?GoogleConnection
    {
        return GoogleConnection::current();
    }

    /**
     * A valid access token for Google, from the cache or newly requested.
     *
     * @throws GoogleApiException
     */
    public function accessToken(): string
    {
        try {
            return $this->account->accessToken();
        } catch (GoogleApiException $e) {
            if ($e->isKeyProblem()) {
                $this->markFailure($e->getMessage());
            }

            throw $e;
        }
    }

    public function forgetAccessToken(): void
    {
        $this->account->forgetAccessToken();
    }

    /**
     * One cheap call to Google (the GA4 accounts shared with us), for the admin's "Test connection".
     *
     * @return array{properties: int, accounts: int}
     *
     * @throws GoogleApiException
     */
    public function testConnection(GoogleApi $api): array
    {
        $properties = $api->properties();
        $this->markSuccess();

        return ['properties' => count($properties), 'accounts' => count(array_unique(array_column($properties, 'account')))];
    }

    public function markSuccess(): void
    {
        $connection = $this->row();

        if ($connection->status !== 'ok' || $connection->last_success_at === null || $connection->last_success_at->lt(now()->subMinute())) {
            $connection->forceFill([
                'status' => 'ok', 'error' => null, 'failure_alerted_at' => null, 'last_success_at' => now(),
                'email' => $this->account->email() ?? $connection->email,
            ])->save();
        }
    }

    public function markFailure(string $error): void
    {
        $connection = $this->row();
        $first = $connection->failure_alerted_at === null;

        $connection->forceFill(['status' => 'error', 'error' => mb_substr($error, 0, 500), 'failure_alerted_at' => $connection->failure_alerted_at ?? now()])->save();

        if ($first) {
            $this->admins->send(fn () => new GoogleConnectionBroken($error));
        }
    }

    public function isBroken(): bool
    {
        try {
            $connection = $this->connection();
        } catch (QueryException) {
            // The table does not exist until the deploy tasks ran: nothing is broken yet.
            return false;
        }

        return $connection !== null && ! $connection->isWorking();
    }

    /**
     * The state row. An old OAuth connection row is taken over: its refresh and access tokens are
     * blanked, because nothing uses them any more and they should not stay in the database.
     */
    private function row(): GoogleConnection
    {
        $connection = $this->connection() ?? GoogleConnection::create(['refresh_token' => '', 'status' => 'ok', 'connected_at' => now()]);

        if ($connection->refresh_token !== '' || $connection->access_token !== null) {
            $connection->forceFill(['refresh_token' => '', 'access_token' => null, 'access_expires_at' => null, 'scopes' => null])->save();
        }

        return $connection;
    }
}
