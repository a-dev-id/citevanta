<?php

namespace App\Services\Google;

use App\Mail\GoogleConnectionBroken;
use App\Models\GoogleConnection;
use App\Services\Budget\AdminMailer;

/**
 * The agent Google account (GOOGLE_AGENT_EMAIL), connected once by an admin
 * with OAuth (offline access, consent prompt). Stores the refresh token
 * encrypted, keeps a short-lived access token, and when Google stops
 * accepting the refresh token (revoked, or expired after 7 days while the
 * Google app is in testing mode) marks the connection broken and emails
 * the admins once.
 */
final class GoogleAgent
{
    public function __construct(private readonly AdminMailer $admins) {}

    public static function redirectUri(): string
    {
        return route('admin.integrations.google.callback');
    }

    public function connection(): ?GoogleConnection
    {
        return GoogleConnection::current();
    }

    /**
     * @throws GoogleApiException
     */
    public function connect(GoogleApi $api, string $code): GoogleConnection
    {
        $tokens = $api->exchangeCode($code);
        $agent = strtolower(trim((string) config('services.google.agent_email')));

        // Only the agent account may be connected: another account would read the wrong properties.
        if ($agent === '' || $tokens['email'] !== $agent || ! $tokens['email_verified']) {
            $api->revoke($tokens['refresh_token'] ?? $tokens['access_token']);

            throw new GoogleApiException($agent === ''
                ? 'Set GOOGLE_AGENT_EMAIL first, then connect again.'
                : "Please sign in as {$agent}.".($tokens['email'] ? " You signed in as {$tokens['email']}." : ''));
        }

        if (($tokens['refresh_token'] ?? null) === null) {
            throw new GoogleApiException('Google did not send a refresh token. Remove the app from the agent account (myaccount.google.com > Security > Third-party access) and connect again.');
        }

        GoogleConnection::query()->delete();

        return GoogleConnection::create([
            'email' => $tokens['email'],
            'refresh_token' => $tokens['refresh_token'],
            'access_token' => $tokens['access_token'],
            'access_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'] - 60)),
            'scopes' => $tokens['scope'],
            'status' => 'ok',
            'connected_at' => now(),
            'last_success_at' => now(),
        ]);
    }

    /**
     * A valid access token, refreshed when it expired.
     *
     * @throws GoogleApiException
     */
    public function accessToken(HttpGoogleApi $api): string
    {
        $connection = $this->connection();

        if ($connection === null || ! $connection->isWorking()) {
            throw new GoogleApiException('Google is not connected. An admin must connect the agent account in Admin > Integrations > Google.', 401, 'not_connected');
        }

        if ($connection->access_token !== null && $connection->access_expires_at?->isFuture()) {
            return $connection->access_token;
        }

        try {
            $data = $api->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        } catch (GoogleApiException $e) {
            if ($e->reason === 'invalid_grant' || $e->status === 400 || $e->status === 401) {
                $this->markFailure('Google no longer accepts the saved token ('.($e->reason ?? $e->status).'). Click "Reconnect".');
            }

            throw $e;
        }

        $connection->forceFill([
            'access_token' => (string) $data['access_token'],
            'access_expires_at' => now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600) - 60)),
        ])->save();

        return (string) $data['access_token'];
    }

    public function forgetAccessToken(): void
    {
        $this->connection()?->forceFill(['access_token' => null, 'access_expires_at' => null])->save();
    }

    public function markSuccess(): void
    {
        $connection = $this->connection();

        if ($connection !== null && ($connection->last_success_at === null || $connection->last_success_at->lt(now()->subMinute()))) {
            $connection->forceFill(['last_success_at' => now()])->save();
        }
    }

    public function markFailure(string $error): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $first = $connection->failure_alerted_at === null;
        $connection->forceFill(['status' => 'error', 'error' => $error, 'failure_alerted_at' => $connection->failure_alerted_at ?? now()])->save();

        if ($first) {
            $this->admins->send(fn () => new GoogleConnectionBroken($error));
        }
    }

    public function isBroken(): bool
    {
        $connection = $this->connection();

        return $connection !== null && ! $connection->isWorking();
    }
}
