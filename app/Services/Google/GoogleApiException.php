<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * A Google API call failed. The message never contains tokens or secrets.
 */
final class GoogleApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    /** Quota or rate limit: try again later. */
    public function isQuota(): bool
    {
        return $this->status === 429 || in_array($this->reason, ['RESOURCE_EXHAUSTED', 'rateLimitExceeded', 'quotaExceeded'], true);
    }

    /** The agent's token no longer works (revoked, expired in testing mode). */
    public function isAuth(): bool
    {
        return $this->status === 401 || $this->reason === 'invalid_grant';
    }

    /** No access to that property or site (not shared with the agent). */
    public function isPermission(): bool
    {
        return $this->status === 403 && ! $this->isQuota();
    }
}
