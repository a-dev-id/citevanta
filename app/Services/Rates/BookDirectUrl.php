<?php

namespace App\Services\Rates;

use App\Models\Hotel;

/**
 * Where the "Book direct" button goes: the hotel's booking engine URL (a
 * setting; it may be a template with {checkin}, {checkout} and {adults}),
 * plus our UTM tags so GA4 can tell these visits apart. The destination
 * always comes from the hotel's own setting, never from the request.
 */
final class BookDirectUrl
{
    public const PLACEHOLDERS = ['{checkin}', '{checkout}', '{adults}'];

    public function build(Hotel $hotel, ?string $checkin = null, int $nights = 1, int $adults = 2): ?string
    {
        $url = trim((string) $hotel->booking_url);

        if ($url === '') {
            return null;
        }

        $checkout = $checkin !== null ? date('Y-m-d', strtotime($checkin.' +'.max(1, $nights).' days')) : '';
        $url = strtr($url, [
            '{checkin}' => rawurlencode((string) $checkin),
            '{checkout}' => rawurlencode($checkout),
            '{adults}' => (string) max(1, $adults),
        ]);

        return $this->withUtm($url);
    }

    /**
     * Adds utm_source and utm_medium; other query parameters and the #fragment stay.
     */
    public function withUtm(string $url): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $query = '';
        if (($mark = strpos($url, '?')) !== false) {
            $query = substr($url, $mark + 1);
            $url = substr($url, 0, $mark);
        }

        parse_str($query, $params);
        $params['utm_source'] = (string) config('citevanta.rates.utm_source');
        $params['utm_medium'] = (string) config('citevanta.rates.utm_medium');

        return $url.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    /**
     * Is this a usable booking URL setting (http or https, templates allowed)?
     */
    public static function isValid(string $url): bool
    {
        $sample = strtr($url, array_combine(self::PLACEHOLDERS, ['2026-12-24', '2026-12-26', '2']));

        return (bool) preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $sample) && filter_var($sample, FILTER_VALIDATE_URL) !== false;
    }
}
