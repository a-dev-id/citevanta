<?php

namespace App\Services\Site;

/**
 * The public website's pages in each language: URLs, hreflang alternates and
 * sitemap entries. English lives at the root (/pricing), Indonesian under
 * /id (/id/pricing). Route names: "pricing" and "id.pricing"; English home
 * is "home".
 */
final class PublicPages
{
    /** Page key => sitemap priority. */
    public const PAGES = [
        'home' => '1.0',
        'pricing' => '0.9',
        'free-audit' => '0.9',
        'contact' => '0.5',
        'privacy' => '0.3',
        'terms' => '0.3',
    ];

    public const DEFAULT_LOCALE = 'en';

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys((array) config('citevanta.site.locales', ['en' => 'English']));
    }

    public static function routeName(string $page, string $locale): string
    {
        return $locale === self::DEFAULT_LOCALE ? $page : "{$locale}.{$page}";
    }

    public static function url(string $page, ?string $locale = null): string
    {
        return route(self::routeName($page, $locale ?? app()->getLocale()));
    }

    /**
     * hreflang alternates for a page, plus x-default (English).
     *
     * @return array<string, string> hreflang => URL
     */
    public static function alternates(string $page): array
    {
        $links = [];
        foreach (self::locales() as $locale) {
            $links[$locale] = self::url($page, $locale);
        }

        return $links + ['x-default' => self::url($page, self::DEFAULT_LOCALE)];
    }

    /**
     * The same page in another language (for the language switcher).
     */
    public static function switchUrl(?string $page, string $locale): string
    {
        return self::url(array_key_exists((string) $page, self::PAGES) ? (string) $page : 'home', $locale);
    }
}
