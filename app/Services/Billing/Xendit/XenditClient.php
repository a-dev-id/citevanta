<?php

namespace App\Services\Billing\Xendit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Xendit's REST API (Laravel HTTP client, no SDK).
 * Auth: HTTP Basic with the secret key as the username and an empty password.
 *
 * - Payment Sessions: POST /sessions, POST /sessions/{id}/cancel
 * - Recurring Plans (api-version 2026-01-01): PATCH /recurring/plans/{id},
 *   POST /recurring/plans/{id}/deactivate
 */
class XenditClient
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createSession(array $body): array
    {
        return $this->send('POST', '/sessions', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelSession(string $sessionId): array
    {
        return $this->send('POST', '/sessions/'.rawurlencode($sessionId).'/cancel');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updatePlan(string $planId, array $body): array
    {
        return $this->send('PATCH', '/recurring/plans/'.rawurlencode($planId), $body, $this->recurringHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivatePlan(string $planId): array
    {
        return $this->send('POST', '/recurring/plans/'.rawurlencode($planId).'/deactivate', [], $this->recurringHeaders());
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = [], array $headers = []): array
    {
        $key = (string) config('services.xendit.secret_key');

        if ($key === '') {
            throw new XenditException('XENDIT_SECRET_KEY is not set.');
        }

        try {
            $response = Http::baseUrl((string) config('services.xendit.base_url', 'https://api.xendit.co'))
                ->withBasicAuth($key, '')
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.xendit.timeout', 20))
                ->withHeaders($headers)
                ->send($method, $path, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new XenditException("Xendit {$method} {$path}: could not connect.");
        }

        if ($response->failed()) {
            $code = $response->json('error_code');

            throw new XenditException(
                sprintf('Xendit %s %s failed: HTTP %d %s %s', $method, $path, $response->status(), $code ?? '', (string) $response->json('message')),
                $response->status(),
                is_string($code) ? $code : null,
            );
        }

        return (array) $response->json();
    }

    /** @return array<string, string> */
    private function recurringHeaders(): array
    {
        return ['api-version' => (string) config('services.xendit.recurring_api_version', '2026-01-01')];
    }
}
