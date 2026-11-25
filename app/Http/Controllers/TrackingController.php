<?php

namespace App\Http\Controllers;

use App\Models\HotelTracking;
use App\Services\Support\Url;
use App\Services\Tracking\TrackingCollector;
use App\Services\Tracking\TrackingScript;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The website tracking script and its endpoint (see routes/tracking.php: no session, no cookies, no CSRF).
 *
 * No IP address and no user agent is ever read here: the rate limit is per public key, the hotel is found by its
 * key, and only the hotel's own website is allowed through CORS. The endpoint answers with a status code and an
 * empty body, so a visitor's browser never shows an error.
 */
class TrackingController extends Controller
{
    public function script(string $key): Response
    {
        $tracking = $this->tracking($key);

        abort_if($tracking === null, 404);

        return response(TrackingScript::build($tracking), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function collect(Request $request, TrackingCollector $collector): Response
    {
        $raw = (string) $request->getContent();
        $data = strlen($raw) <= TrackingCollector::MAX_BODY ? json_decode($raw, true) : null;

        if (strlen($raw) > TrackingCollector::MAX_BODY) {
            return response('', 413);
        }

        if (! is_array($data)) {
            return response('', 400);
        }

        $tracking = $this->tracking(is_string($data['k'] ?? null) ? $data['k'] : '');

        if ($tracking === null) {
            return response('', 404);
        }

        $origin = (string) $request->headers->get('Origin', '');

        if (! self::originAllowed($origin, (string) $tracking->hotel->website)) {
            return response('', 403);
        }

        if ($collector->store($tracking, $data) === null) {
            return response('', 422, self::cors($origin));
        }

        return response('', 204, self::cors($origin));
    }

    /** Preflight requests are not allowed: the script only sends simple requests. */
    public function preflight(): Response
    {
        return response('', 204);
    }

    /** The hotel's own website only: the Origin header must be the hotel's domain or a subdomain of it (never a parent domain). */
    public static function originAllowed(string $origin, string $website): bool
    {
        $from = Url::host($origin);
        $site = Url::host($website);

        return $origin !== '' && $from !== '' && $site !== '' && ($from === $site || str_ends_with($from, '.'.$site));
    }

    /**
     * @return array<string, string>
     */
    private static function cors(string $origin): array
    {
        return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Credentials' => 'true', 'Vary' => 'Origin', 'Cache-Control' => 'no-store'];
    }

    private function tracking(string $key): ?HotelTracking
    {
        if (! preg_match('/^cvk_[a-z0-9]{24}$/', $key)) {
            return null;
        }

        $tracking = HotelTracking::query()->with('hotel.team')->where('public_key', $key)->where('enabled', true)->first();
        $team = $tracking?->hotel?->team;

        return $team !== null && $team->isPrivate() && ! $team->isReadOnly() ? $tracking : null;
    }
}
