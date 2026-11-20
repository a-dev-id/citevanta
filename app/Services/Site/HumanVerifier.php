<?php

namespace App\Services\Site;

/**
 * Checks that a public form was sent by a person (Cloudflare Turnstile).
 */
interface HumanVerifier
{
    /** Whether a challenge is shown at all (keys configured). */
    public function enabled(): bool;

    public function siteKey(): ?string;

    public function verify(?string $token, ?string $ip): bool;
}
