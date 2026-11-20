<?php

namespace App\Services\Ai;

/**
 * Tests an OpenAI API key with one cheap request (list models).
 */
interface OpenAiKeyChecker
{
    /**
     * @return array{ok: bool, error: ?string, code: ?string}
     */
    public function check(string $key): array;
}
