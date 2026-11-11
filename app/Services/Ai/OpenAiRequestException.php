<?php

namespace App\Services\Ai;

use RuntimeException;

class OpenAiRequestException extends RuntimeException
{
    /** @param array<string, mixed> $diagnostics Safe metadata only; never include the response body or headers. */
    public function __construct(string $message, public readonly array $diagnostics)
    {
        parent::__construct($message);
    }
}
