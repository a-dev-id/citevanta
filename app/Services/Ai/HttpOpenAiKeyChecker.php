<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GET https://api.openai.com/v1/models with the key (no tokens are used).
 * Returns OpenAI's own error message and code; the key is never logged.
 */
final class HttpOpenAiKeyChecker implements OpenAiKeyChecker
{
    public function check(string $key): array
    {
        try {
            $response = Http::withToken($key)->acceptJson()->timeout(15)
                ->get(rtrim((string) (config('openai.base_uri') ?: 'https://api.openai.com/v1'), '/').'/models');
        } catch (ConnectionException) {
            return ['ok' => false, 'error' => 'Could not reach OpenAI. Please try again in a minute.', 'code' => 'connection'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'code' => null];
        }

        $code = $response->json('error.code') ?? $response->json('error.type');

        return [
            'ok' => false,
            'error' => sprintf('OpenAI answered %d: %s', $response->status(), (string) ($response->json('error.message') ?? 'unknown error')),
            'code' => is_string($code) ? $code : null,
        ];
    }
}
