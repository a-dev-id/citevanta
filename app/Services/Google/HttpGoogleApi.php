<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google APIs with Laravel's HTTP client (no SDK). Requests use the service
 * account's access token (GoogleAgent::accessToken(), cached for about 50
 * minutes). When Google refuses the key, GoogleAgent marks the connection
 * broken and alerts the admins.
 */
final class HttpGoogleApi implements GoogleApi
{
    public function __construct(private readonly GoogleAgent $agent) {}

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
        $token = $this->agent->accessToken();

        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('services.google.timeout', 30))->{$method}($url, $payload);
        } catch (ConnectionException) {
            throw new GoogleApiException('Could not reach Google.');
        }

        if ($response->status() === 401) {
            // The access token was revoked early: ask for a new one once and retry.
            $this->agent->forgetAccessToken();
            $response = Http::withToken($this->agent->accessToken())->acceptJson()->timeout((int) config('services.google.timeout', 30))->{$method}($url, $payload);
        }

        if ($response->failed()) {
            throw self::error($response, $url);
        }

        $this->agent->markSuccess();

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
