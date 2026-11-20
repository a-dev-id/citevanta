<?php

namespace App\Services\Support;

final class Url
{
    /**
     * Trim a user-entered website and add https:// when no scheme is given.
     * Returns null for an empty value.
     */
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        return preg_match('#^https?://#i', $url) ? $url : 'https://'.$url;
    }

    /**
     * The host of a URL or bare domain, lower case, without "www." and port:
     * "https://www.Nandini.com/rooms" -> "nandini.com". Empty when none.
     */
    public static function host(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $host = (string) parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'https://'.$url, PHP_URL_HOST);

        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Whether two hosts are the same site: equal, or one a subdomain of the other.
     */
    public static function sameSite(string $a, string $b): bool
    {
        $a = self::host($a);
        $b = self::host($b);

        return $a !== '' && $b !== '' && ($a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a));
    }
}
