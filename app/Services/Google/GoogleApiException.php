<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * A Google API call failed. The message never contains keys, tokens or secrets.
 */
final class GoogleApiException extends RuntimeException
{
    /** Reasons that mean the service account key itself is the problem (missing, unreadable, or refused by Google). */
    private const KEY_REASONS = ['not_configured', 'key_missing', 'key_invalid', 'invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request', 'access_denied'];

    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    /** Quota or rate limit: try again later. */
    public function isQuota(): bool
    {
        return $this->status === 429 || in_array($this->reason, ['RESOURCE_EXHAUSTED', 'rateLimitExceeded', 'quotaExceeded'], true);
    }

    /** Google no longer accepts our credentials (an access token revoked early, or a refused key). */
    public function isAuth(): bool
    {
        return $this->status === 401 || $this->isKeyProblem();
    }

    /** The key file is not set, missing, not a valid key, or Google refuses it (deleted, disabled, wrong). */
    public function isKeyProblem(): bool
    {
        return in_array($this->reason, self::KEY_REASONS, true);
    }

    /** No access to that property or site (not shared with the service account). */
    public function isPermission(): bool
    {
        return $this->status === 403 && ! $this->isQuota();
    }
}
