<?php

namespace App\Services\Google;

use App\Services\Support\Url;

/**
 * Recognises AI platforms from a referrer domain, a GA4 session source or
 * a utm_source value (config citevanta.ai_sources).
 */
final class AiSources
{
    public static function platform(?string $source): ?string
    {
        $source = strtolower(trim((string) $source));

        if ($source === '' || $source === '(direct)' || $source === '(not set)') {
            return null;
        }

        $map = (array) config('citevanta.ai_sources', []);

        if (isset($map[$source])) {
            return $map[$source];
        }

        $host = Url::host($source);

        foreach ($map as $key => $platform) {
            if (str_contains($key, '.') && Url::sameSite($host, $key)) {
                return $platform;
            }
        }

        return null;
    }
}
