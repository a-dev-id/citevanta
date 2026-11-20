<?php

namespace App\Services\Scanning;

use RuntimeException;

/**
 * "Scan now" was refused; the message is safe to show to the user.
 */
final class ManualScanNotAllowed extends RuntimeException
{
    public function __construct(public readonly ManualScanCheck $check)
    {
        parent::__construct($check->reason ?? 'A manual scan is not available right now.');
    }
}
