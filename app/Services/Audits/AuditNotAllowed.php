<?php

namespace App\Services\Audits;

use RuntimeException;

/**
 * A free audit request refused by the limits (per IP / per email / daily
 * limit and the audits budget). fullyBooked: offer the waitlist.
 */
final class AuditNotAllowed extends RuntimeException
{
    public bool $fullyBooked = false;

    public static function tooMany(): self
    {
        return new self(__('audit.errors.too_many'));
    }

    public static function budget(): self
    {
        $e = new self(__('audit.errors.budget'));
        $e->fullyBooked = true;

        return $e;
    }
}
