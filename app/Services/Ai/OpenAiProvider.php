<?php

namespace App\Services\Ai;

use App\Services\PaidExecutionGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProviderAdapter
{
    public function execute(array $settings): array
    {
        if (! config('intelligence.openai_key')) {
            throw new OpenAiRequestException('OpenAI API key is not configured. Set NANDINI_OPENAI_API_KEY in the server environment.', ['error' => ['code' => 'missing_api_key']]);
        }
        app(PaidExecutionGate::class)->consume($settings);
        try {
            $response = Http::withOptions(['verify' => config('intelligence.ca_bundle') ? base_path(config('intelligence.ca_bundle')) : true])
                ->withToken(config('intelligence.openai_key'))
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(config('intelligence.timeout'))
                ->post('https://api.openai.com/v1/responses', $settings);
        } catch (ConnectionException $exception) {
            $previous = $exception;
            $curlError = null;
            while ($previous !== null) {
                if (method_exists($previous, 'getHandlerContext')) {
                    $curlError = $previous->getHandlerContext()['errno'] ?? null;
                    break;
                }
                if (preg_match('/\bcURL error (\d+):/', $previous->getMessage(), $matches)) {
                    $curlError = (int) $matches[1];
                    break;
                }
                $previous = $previous->getPrevious();
            }
            $tlsFailure = $curlError === 60;
            throw new OpenAiRequestException(
                $tlsFailure
                    ? 'TLS certificate verification failed before the API request. Configure a trusted OPENAI_CA_BUNDLE on the server; do not disable certificate verification.'
                    : 'Connection failed or timed out. The provider may have processed this request; it will not be retried automatically.',
                ['error' => ['code' => $tlsFailure ? 'tls_certificate_error' : 'connection_error'], 'curl_errno' => $curlError],
            );
        }

        $requestId = $response->header('x-request-id');
        $requestId = is_string($requestId) && preg_match('/^req_[a-zA-Z0-9_-]{1,100}$/D', $requestId) ? $requestId : null;
        if (! $response->successful()) {
            $knownCodes = [
                'invalid_api_key', 'insufficient_quota', 'credit_balance_exhausted',
                'project_spend_limit_exceeded', 'organization_spend_limit_exceeded',
                'organization_usage_limit_exceeded', 'rate_limit_exceeded', 'model_not_found',
                'permission_denied', 'unsupported_parameter', 'unsupported_value', 'invalid_value',
                'invalid_parameter', 'server_error', 'ip_not_authorized',
            ];
            $knownTypes = ['invalid_request_error', 'insufficient_quota', 'rate_limit_error', 'authentication_error', 'permission_error', 'server_error'];
            $code = $response->json('error.code');
            $code = in_array($code, $knownCodes, true) ? $code : 'unknown_error';
            $type = $response->json('error.type');
            $type = in_array($type, $knownTypes, true) ? $type : 'unknown_error';
            $message = match (true) {
                $code === 'invalid_api_key', $response->status() === 401 => 'OpenAI authentication failed. Verify NANDINI_OPENAI_API_KEY on the server and clear stale configuration; the key may be invalid, revoked, or overridden.',
                in_array($code, ['insufficient_quota', 'credit_balance_exhausted', 'project_spend_limit_exceeded', 'organization_spend_limit_exceeded', 'organization_usage_limit_exceeded'], true), $type === 'insufficient_quota' => 'OpenAI API billing or usage quota is unavailable. Check API credits and project/organization spending limits before running another test.',
                $code === 'model_not_found', $response->status() === 404 => 'The configured OpenAI model is unavailable to this project. Verify OPENAI_MODEL and project model permissions.',
                $response->status() === 403 => 'OpenAI denied this project access. Check API key permissions, model access, and organization restrictions.',
                $response->status() === 429 => 'OpenAI rate or credit limit reached. Check API limits before manually running another test.',
                $response->status() === 400 => 'OpenAI rejected the request configuration. Check the configured model and supported web_search settings.',
                $response->status() >= 500 => 'OpenAI encountered a service error. Check provider status before manually running another test.',
                default => 'OpenAI could not complete the request.',
            };
            $retryAfter = $response->header('retry-after');
            $retrySeconds = null;
            if ($response->status() === 429 && is_string($retryAfter)) {
                $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (($date = strtotime($retryAfter)) !== false ? $date - time() : 0);
                $retrySeconds = max(1, min(86400, $seconds));
            }
            throw new OpenAiRequestException(
                $message.' (HTTP '.$response->status().'; '.$code.'). No automatic retry was made.',
                ['http_status' => $response->status(), 'error' => ['code' => $code, 'type' => $type], 'request_id' => $requestId, 'retry_after_seconds' => $retrySeconds],
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! is_string($data['id'] ?? null) || $data['id'] === '' || ! is_array($data['output'] ?? null)) {
            throw new OpenAiRequestException('OpenAI returned an invalid response. No automatic retry was made.', ['http_status' => $response->status(), 'error' => ['code' => 'invalid_response'], 'request_id' => $requestId]);
        }

        return $data;
    }
}
