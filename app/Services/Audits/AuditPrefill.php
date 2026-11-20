<?php

namespace App\Services\Audits;

use App\Models\Audit;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Carries a free audit from its report into the trial: registration is
 * prefilled (email, hotel name as team name), the setup wizard is prefilled
 * (hotel name, city, country, website), and the new team is recorded as a
 * conversion of that lead. The token travels in the session.
 */
final class AuditPrefill
{
    public const SESSION_KEY = 'audit_prefill';

    /**
     * On the registration page: remember the audit from ?audit=token.
     */
    public static function remember(Request $request): ?Audit
    {
        $token = (string) $request->query('audit', '');

        if ($token !== '' && ($audit = Audit::query()->where('token', $token)->first()) !== null) {
            $request->session()->put(self::SESSION_KEY, $audit->token);

            return $audit;
        }

        return self::current();
    }

    public static function current(): ?Audit
    {
        $token = session(self::SESSION_KEY);

        return is_string($token) ? Audit::query()->where('token', $token)->first() : null;
    }

    /**
     * After registration: the lead became this team.
     */
    public static function convert(Team $team): void
    {
        $audit = self::current();

        if ($audit !== null) {
            app(AuditService::class)->markConverted($audit, $team);
        }
    }

    /**
     * Setup wizard values for a new hotel, then forget the audit.
     *
     * @return array{name: string, city: string, country: string, website: string}|null
     */
    public static function hotelDetails(): ?array
    {
        $audit = self::current();

        if ($audit === null) {
            return null;
        }

        session()->forget(self::SESSION_KEY);

        return [
            'name' => $audit->hotel_name,
            'city' => $audit->city,
            'country' => $audit->country,
            'website' => (string) $audit->website,
        ];
    }
}
