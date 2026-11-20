<?php

namespace App\Services\Site;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, verified server-side with Laravel's HTTP client.
 * Without TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY it is off (forms keep
 * their honeypot). If Cloudflare cannot be reached the form is refused,
 * so an outage never opens the forms to bots.
 */
final class TurnstileVerifier implements HumanVerifier
{
    public function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return $this->enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post((string) config('services.turnstile.verify_url'), array_filter([
                'secret' => (string) config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException) {
            Log::warning('Turnstile: could not reach Cloudflare.');

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
