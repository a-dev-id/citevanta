<?php

namespace App\Services\Trials;

use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Registration rules for new trials: no disposable email domains, and at
 * most citevanta.trial.max_per_ip trials per IP address in ip_window_days.
 * IP addresses are stored hashed (teams.signup_ip_hash).
 */
final class TrialSignupGuard
{
    public function isDisposable(string $email): bool
    {
        $domain = Str::lower(trim(Str::after($email, '@')));

        if ($domain === '') {
            return false;
        }

        foreach ((array) config('citevanta.trial.disposable_email_domains', []) as $blocked) {
            $blocked = Str::lower((string) $blocked);

            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    public function tooManyFromIp(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        return Team::query()
            ->where('signup_ip_hash', self::hashIp($ip))
            ->where('created_at', '>=', now()->subDays((int) config('citevanta.trial.ip_window_days', 30)))
            ->count() >= (int) config('citevanta.trial.max_per_ip', 2);
    }

    /**
     * Record how the trial started: the hashed IP and whether it needs an admin's approval.
     */
    public function record(Team $team, ?string $ip): void
    {
        $team->forceFill([
            'signup_ip_hash' => $ip ? self::hashIp($ip) : null,
            'trial_approval_required' => (bool) config('citevanta.trial.requires_approval', false),
        ])->save();
    }

    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
