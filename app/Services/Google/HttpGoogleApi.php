<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google APIs with Laravel's HTTP client (no SDK). Requests use the agent's
 * access token, refreshed with the stored refresh token when it expires.
 * When Google refuses the refresh token, GoogleAgent marks the connection
 * broken and alerts the admins.
 */
final class HttpGoogleApi implements GoogleApi
{
    public function __construct(private readonly GoogleAgent $agent) {}

    public function authorizationUrl(string $state): string
    {
        return config('services.google.auth_url').'?'.http_build_query(array_filter([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => GoogleAgent::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('services.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => config('services.google.agent_email'),
            'state' => $state,
        ]));
    }

    public function exchangeCode(string $code): array
    {
        $data = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => GoogleAgent::redirectUri(),
        ]);

        $identity = $this->identity($data);

        return [
            'refresh_token' => $data['refresh_token'] ?? null,
            'access_token' => (string) ($data['access_token'] ?? ''),
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'scope' => (string) ($data['scope'] ?? ''),
            'email' => $identity['email'],
            'email_verified' => $identity['email_verified'],
        ];
    }

    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout((int) config('services.google.timeout', 30))->post((string) config('services.google.revoke_url'), ['token' => $token]);
        } catch (ConnectionException) {
            // Best effort: an unused token also expires on its own.
        }
    }

    /**
     * The signed-in account from the ID token of the code exchange (it comes
     * straight from Google's token endpoint over HTTPS, so Google's docs allow
     * reading it without verifying the signature), else from the userinfo endpoint.
     *
     * @param  array<string, mixed>  $tokens
     * @return array{email: ?string, email_verified: bool}
     */
    private function identity(array $tokens): array
    {
        $parts = explode('.', (string) ($tokens['id_token'] ?? ''));
        $claims = count($parts) === 3 ? (array) json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : [];

        if (! isset($claims['email']) && isset($tokens['access_token'])) {
            try {
                $claims = (array) Http::withToken((string) $tokens['access_token'])->acceptJson()->timeout((int) config('services.google.timeout', 30))
                    ->get((string) config('services.google.userinfo_url'))->json();
            } catch (ConnectionException) {
                $claims = [];
            }
        }

        return [
            'email' => isset($claims['email']) ? strtolower((string) $claims['email']) : null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    public function properties(): array
    {
        $properties = [];
        $pageToken = null;

        // One request per page of up to 200 accounts (usually one page).
        do {
            $data = $this->get(config('services.google.analytics_admin_url').'/accountSummaries', array_filter(['pageSize' => 200, 'pageToken' => $pageToken]));

            foreach ((array) ($data['accountSummaries'] ?? []) as $account) {
                foreach ((array) ($account['propertySummaries'] ?? []) as $property) {
                    $properties[] = [
                        'property' => (string) $property['property'],
                        'displayName' => (string) ($property['displayName'] ?? $property['property']),
                        'account' => (string) ($account['displayName'] ?? ''),
                    ];
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $properties;
    }

    public function webStreamUrls(string $property): array
    {
        $data = $this->get(config('services.google.analytics_admin_url').'/'.$property.'/dataStreams', ['pageSize' => 200]);

        return array_values(array_filter(array_map(
            fn (array $stream) => $stream['webStreamData']['defaultUri'] ?? null,
            (array) ($data['dataStreams'] ?? []),
        )));
    }

    public function propertyDetails(string $property): array
    {
        $data = $this->get(config('services.google.analytics_admin_url').'/'.$property);

        return ['timeZone' => $data['timeZone'] ?? null, 'currencyCode' => $data['currencyCode'] ?? null];
    }

    public function customDimensions(string $property): array
    {
        $data = $this->get(config('services.google.analytics_admin_url').'/'.$property.'/customDimensions', ['pageSize' => 200]);

        return array_values(array_map(fn (array $dimension) => [
            'parameterName' => (string) ($dimension['parameterName'] ?? ''),
            'displayName' => (string) ($dimension['displayName'] ?? ''),
            'scope' => (string) ($dimension['scope'] ?? ''),
        ], (array) ($data['customDimensions'] ?? [])));
    }

    public function runReport(string $property, array $body): array
    {
        return $this->send('post', config('services.google.analytics_data_url').'/'.$property.':runReport', $body);
    }

    public function sites(): array
    {
        $data = $this->get(config('services.google.search_console_url').'/sites');

        return array_values(array_map(fn (array $site) => [
            'siteUrl' => (string) $site['siteUrl'],
            'permissionLevel' => (string) ($site['permissionLevel'] ?? ''),
        ], (array) ($data['siteEntry'] ?? [])));
    }

    public function searchAnalytics(string $siteUrl, array $body): array
    {
        return $this->send('post', config('services.google.search_console_url').'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query', $body);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        return $this->send('get', $url, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $payload = []): array
    {
        $token = $this->agent->accessToken($this);

        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('services.google.timeout', 30))->{$method}($url, $payload);
        } catch (ConnectionException) {
            throw new GoogleApiException('Could not reach Google.');
        }

        if ($response->status() === 401) {
            // The access token was revoked early: refresh once and retry.
            $this->agent->forgetAccessToken();
            $response = Http::withToken($this->agent->accessToken($this))->acceptJson()->timeout((int) config('services.google.timeout', 30))->{$method}($url, $payload);
        }

        if ($response->failed()) {
            throw self::error($response, $url);
        }

        $this->agent->markSuccess();

        return (array) $response->json();
    }

    /**
     * Token endpoint call (code exchange or refresh), used by GoogleAgent too.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public function tokenRequest(array $fields): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout((int) config('services.google.timeout', 30))->post((string) config('services.google.token_url'), $fields + [
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new GoogleApiException('Could not reach Google.');
        }

        if ($response->failed()) {
            throw new GoogleApiException(
                'Google refused the token request: '.(string) ($response->json('error_description') ?? $response->json('error') ?? $response->status()),
                $response->status(),
                is_string($response->json('error')) ? $response->json('error') : null,
            );
        }

        return (array) $response->json();
    }

    private static function error(Response $response, string $url): GoogleApiException
    {
        $reason = $response->json('error.status') ?? $response->json('error.errors.0.reason');
        $path = (string) parse_url($url, PHP_URL_PATH);

        return new GoogleApiException(
            sprintf('Google %s answered %d: %s', $path, $response->status(), (string) ($response->json('error.message') ?? 'error')),
            $response->status(),
            is_string($reason) ? $reason : null,
        );
    }
}
