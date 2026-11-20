<?php

namespace App\Services\Dashboard;

use App\Enums\DomainType;
use App\Services\Analysis\HotelNameMatcher;

/**
 * Sorts a cited domain into a DomainType: the hotel's own website, a tracked
 * competitor, or one of the configured lists (config: citevanta.domain_types).
 *
 * Order: own website, competitor website, OTA, review site, travel media,
 * social, competitor name in the domain, hotel chain, other.
 */
final class DomainClassifier
{
    /** @var array<string, list<string>> */
    private array $lists;

    /**
     * @param  array<string, list<string>>|null  $lists  type value => domain patterns
     */
    public function __construct(?array $lists = null, private readonly HotelNameMatcher $matcher = new HotelNameMatcher)
    {
        $this->lists = $lists ?? (array) config('citevanta.domain_types', []);
    }

    public function classify(string $domain, DomainContext $context): DomainType
    {
        $domain = self::normalize($domain);

        if ($context->ownDomain !== null && $context->ownDomain !== '' && self::isSameSite($domain, $context->ownDomain)) {
            return DomainType::Direct;
        }

        foreach ($context->competitors as $competitor) {
            if ($competitor['domain'] !== null && $competitor['domain'] !== '' && self::isSameSite($domain, $competitor['domain'])) {
                return DomainType::Competitor;
            }
        }

        foreach ([DomainType::Ota, DomainType::ReviewSite, DomainType::TravelMedia, DomainType::Social] as $type) {
            if ($this->inList($domain, $type)) {
                return $type;
            }
        }

        $ignore = $context->city !== '' ? [$context->city] : [];
        foreach ($context->competitors as $competitor) {
            if ($this->matcher->appearsInDomain($domain, $competitor['name'], $ignore)) {
                return DomainType::Competitor;
            }
        }

        return $this->inList($domain, DomainType::HotelChain) ? DomainType::HotelChain : DomainType::Other;
    }

    private function inList(string $domain, DomainType $type): bool
    {
        foreach ($this->lists[$type->value] ?? [] as $pattern) {
            if (self::matches($domain, strtolower(trim($pattern)))) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $domain): string
    {
        return strtolower(preg_replace('/^www\./', '', trim($domain)) ?? '');
    }

    private static function isSameSite(string $domain, string $site): bool
    {
        $site = self::normalize($site);

        return $domain === $site || str_ends_with($domain, '.'.$site);
    }

    private static function matches(string $domain, string $pattern): bool
    {
        if (str_ends_with($pattern, '.*')) {
            $name = preg_quote(substr($pattern, 0, -2), '/');

            return preg_match('/(^|\.)'.$name.'\.[a-z]{2,}(\.[a-z]{2,})?$/', $domain) === 1;
        }

        return self::isSameSite($domain, $pattern);
    }
}
