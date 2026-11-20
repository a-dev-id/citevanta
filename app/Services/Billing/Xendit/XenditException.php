<?php

namespace App\Services\Billing\Xendit;

use RuntimeException;

/**
 * A Xendit API call failed. The message never contains the secret key.
 */
final class XenditException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
