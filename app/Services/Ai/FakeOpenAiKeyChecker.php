<?php

namespace App\Services\Ai;

/**
 * CITEVANTA_ENGINE=fake: keys starting with "sk-" work, others fail like a
 * wrong key ("sk-bad..." fails too).
 */
final class FakeOpenAiKeyChecker implements OpenAiKeyChecker
{
    public function check(string $key): array
    {
        if (str_starts_with($key, 'sk-') && ! str_starts_with($key, 'sk-bad')) {
            return ['ok' => true, 'error' => null, 'code' => null];
        }

        return ['ok' => false, 'error' => 'OpenAI answered 401: Incorrect API key provided.', 'code' => 'invalid_api_key'];
    }
}
