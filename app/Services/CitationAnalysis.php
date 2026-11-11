<?php

namespace App\Services;

use App\Models\Citation;
use Illuminate\Support\Collection;

class CitationAnalysis
{
    public const TYPES = ['nandini_official', 'competitor_official', 'booking_platform', 'third_party_travel', 'map_listing', 'other', 'unverified'];

    public function normalize(string $url): ?array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }
        $domain = strtolower(rtrim(parse_url($url, PHP_URL_HOST) ?? '', '.'));
        $domain = preg_replace('/^www\./', '', $domain);
        if ($domain === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $segments = [];
        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = '/'.implode('/', $segments).(str_ends_with($path, '/') && $segments ? '/' : '');
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower($key), 'utm_') || in_array($key, ['gclid', 'fbclid'])) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return ['domain' => $domain, 'path' => $path, 'url' => 'https://'.$domain.$path.($query ? '?'.http_build_query($query) : '')];
    }

    public function matchesWebsite(string $url, string $website): bool
    {
        $a = $this->normalize($url);
        $b = $this->normalize($website);
        if (! $a || ! $b || ! ($a['domain'] === $b['domain'] || str_ends_with($a['domain'], '.'.$b['domain']))) {
            return false;
        }
        $prefix = rtrim($b['path'], '/');

        return $prefix === '' || $a['path'] === $prefix || str_starts_with($a['path'], $prefix.'/');
    }

    public function classify(Citation $citation, Collection $hotels, bool $persist = true): void
    {
        $url = $this->normalize($citation->url);
        $base = ['normalized_domain' => $url['domain'] ?? null, 'normalized_url' => $url['url'] ?? null];
        if ($citation->manual) {
            $official = in_array($citation->classification, ['nandini_official', 'competitor_official'], true);
            $hotel = $hotels->firstWhere('id', $citation->hotel_id);
            if ($official && (! $hotel || ($citation->classification === 'nandini_official') !== $hotel->isMonitoredBrand() || ! collect($hotel->official_websites)->contains(fn ($site) => $this->matchesWebsite($citation->url, $site)))) {
                $this->apply($citation, $persist, [...$base, 'classification' => 'unverified', 'hotel_id' => null, 'is_official' => false]);
            } else {
                $this->apply($citation, $persist, [...$base, 'is_official' => $citation->classification === 'nandini_official']);
            }

            return;
        }
        $matches = $url ? $hotels->filter(fn ($hotel) => collect($hotel->official_websites)->contains(fn ($site) => $this->matchesWebsite($citation->url, $site))) : collect();
        $hotel = $matches->count() === 1 ? $matches->first() : null;
        $category = 'other';
        if (! $url) {
            $category = 'unverified';
        } elseif ($hotel) {
            $category = $hotel->isMonitoredBrand() ? 'nandini_official' : 'competitor_official';
        } elseif (($url['domain'] === 'google.com' || str_ends_with($url['domain'], '.google.com')) && str_starts_with($url['path'], '/maps/')) {
            $category = 'map_listing';
        } elseif ($this->inDomains($url['domain'], config('visibility.booking_domains'))) {
            $category = 'booking_platform';
        } elseif ($this->inDomains($url['domain'], config('visibility.travel_domains'))) {
            $category = 'third_party_travel';
        }
        $this->apply($citation, $persist, [...$base, 'classification' => $category, 'hotel_id' => $hotel?->id, 'is_official' => $category === 'nandini_official']);
    }

    private function apply(Citation $citation, bool $persist, array $attributes): void
    {
        $citation->fill($attributes);
        if ($persist) {
            $citation->save();
        }
    }

    public static function label(?string $type): string
    {
        return match ($type) {
            'nandini_official' => 'Monitored brand official',
            'competitor_official' => 'Competitor official',
            'booking_platform' => 'Booking platform',
            'third_party_travel' => 'Travel publication',
            'map_listing' => 'Map listing',
            'unverified', null => 'Unverified source',
            default => 'Third-party source',
        };
    }

    private function inDomains(string $domain, array $known): bool
    {
        return collect($known)->contains(fn ($host) => $domain === $host || str_ends_with($domain, '.'.$host));
    }
}
