<?php

namespace App\Services\Content;

use RuntimeException;

/**
 * The team used all its content generations for this month.
 */
final class ContentLimitReached extends RuntimeException
{
    public function __construct(public readonly ?int $limit, ?string $message = null)
    {
        parent::__construct($message ?? "You have used all {$limit} content generations for this month. They reset on the 1st.");
    }

    /**
     * The subscription has expired: the account is read-only.
     */
    public static function readOnly(): self
    {
        return new self(null, 'Your subscription has expired. Renew it on Settings > Billing to create content again.');
    }
}
