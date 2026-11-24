<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The Google service account the app reads GA4 and Search Console with.
 *
 * The key file (JSON) is read from GOOGLE_SERVICE_ACCOUNT_JSON_PATH when it is
 * needed. Its contents are never stored in the database, shown, sent to the
 * browser, logged, or put in an error message: only the e-mail address
 * (client_email, which hotels add in GA4 and Search Console) is ever shown.
 *
 * A short-lived access token comes from a signed JWT (RS256) exchanged at
 * Google's token endpoint, and is cached (encrypted) for about 50 minutes.
 */
final class GoogleServiceAccount
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.google.service_account_json_path')) !== '';
    }

    /**
     * The key file path: absolute as written, otherwise relative to storage_path().
     */
    public function path(): string
    {
        $path = trim((string) config('services.google.service_account_json_path'));

        if ($path === '') {
            return '';
        }

        return preg_match('#^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $path) === 1 ? $path : storage_path($path);
    }

    /**
     * What the admin page shows about the key file. Never any contents.
     *
     * @return array{configured: bool, found: bool, valid: bool, email: ?string, path: string, problem: ?string}
     */
    public function status(): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false, 'found' => false, 'valid' => false, 'email' => null, 'path' => '', 'problem' => null];
        }

        try {
            $email = (string) $this->credentials()['client_email'];

            return ['configured' => true, 'found' => true, 'valid' => true, 'email' => $email, 'path' => $this->path(), 'problem' => null];
        } catch (GoogleApiException $e) {
            return ['configured' => true, 'found' => $e->reason !== 'key_missing', 'valid' => false, 'email' => null, 'path' => $this->path(), 'problem' => $e->getMessage()];
        }
    }

    /**
     * The e-mail address hotels share GA4 and Search Console with (client_email in the key file).
     */
    public function email(): ?string
    {
        return $this->status()['email'];
    }

    /**
     * An access token for the Google APIs, from the cache or from a new signed request.
     *
     * @throws GoogleApiException
     */
    public function accessToken(): string
    {
        $credentials = $this->credentials();
        $key = $this->cacheKey($credentials);

        if (is_string($cached = Cache::get($key))) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($key);
            }
        }

        $assertion = $this->signedJwt($credentials);

        try {
            $response = Http::asForm()->acceptJson()->timeout((int) config('services.google.timeout', 30))
                ->post((string) config('services.google.token_url'), ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        } catch (ConnectionException) {
            throw new GoogleApiException('Could not reach Google.');
        }

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            $reason = is_string($response->json('error')) ? $response->json('error') : null;

            throw new GoogleApiException(
                'Google refused the service account key: '.$this->safe((string) ($response->json('error_description') ?? $reason ?? 'HTTP '.$response->status())),
                $response->status(),
                $reason ?? 'invalid_grant',
            );
        }

        $token = (string) $response->json('access_token');
        $minutes = min((int) config('services.google.token_cache_minutes', 50), max(1, intdiv((int) ($response->json('expires_in') ?? 3600) - 120, 60)));
        Cache::put($key, Crypt::encryptString($token), now()->addMinutes($minutes));

        return $token;
    }

    public function forgetAccessToken(): void
    {
        try {
            Cache::forget($this->cacheKey($this->credentials()));
        } catch (GoogleApiException) {
            // No readable key, so there is no token to forget.
        }
    }

    /**
     * The key file, checked. The returned array is only passed to signedJwt() and never leaves this class.
     *
     * @return array<string, mixed>
     *
     * @throws GoogleApiException with a message that contains no part of the file
     */
    private function credentials(): array
    {
        if (! $this->isConfigured()) {
            throw new GoogleApiException('The Google key file is not set (GOOGLE_SERVICE_ACCOUNT_JSON_PATH).', null, 'not_configured');
        }

        $path = $this->path();

        if (! is_file($path) || ! is_readable($path)) {
            throw new GoogleApiException('The Google key file was not found or cannot be read at '.$path.'.', null, 'key_missing');
        }

        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data)) {
            throw new GoogleApiException('The Google key file is not valid JSON.', null, 'key_invalid');
        }

        if (($data['type'] ?? null) !== 'service_account'
            || ! is_string($data['client_email'] ?? null) || filter_var($data['client_email'], FILTER_VALIDATE_EMAIL) === false
            || ! is_string($data['private_key'] ?? null) || ! str_contains($data['private_key'], 'PRIVATE KEY')) {
            throw new GoogleApiException('The Google key file is not a service account key (it needs type, client_email and private_key).', null, 'key_invalid');
        }

        if (openssl_pkey_get_private($data['private_key']) === false) {
            throw new GoogleApiException('The private key in the Google key file cannot be read.', null, 'key_invalid');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function signedJwt(#[SensitiveParameter] array $credentials): string
    {
        $now = time();
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $input = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $credentials['client_email'],
            'scope' => implode(' ', (array) config('services.google.scopes')),
            'aud' => (string) config('services.google.token_url'),
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $signature = '';

        if (! @openssl_sign($input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new GoogleApiException('The private key in the Google key file cannot sign a request.', null, 'key_invalid');
        }

        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * The cache key depends on the account and key id (neither is secret), so a replaced key file never reuses an old token.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function cacheKey(#[SensitiveParameter] array $credentials): string
    {
        return 'google:service-account-token:'.substr(sha1($credentials['client_email'].'|'.($credentials['private_key_id'] ?? '')), 0, 16);
    }

    /**
     * Text from Google that goes into messages: whatever it is, no key block gets through.
     */
    private function safe(string $text): string
    {
        $text = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[private key removed]', $text);

        return mb_substr($text, 0, 300);
    }
}
