<?php

namespace App\Services\Dashboard;

use App\Models\Hotel;
use App\Services\Scanning\ScanFinalizer;

/**
 * What DomainClassifier needs to know about one hotel: its own domain, its
 * tracked competitors (name and website domain) and its city (ignored when
 * looking for a competitor's name inside a domain).
 */
final class DomainContext
{
    /**
     * @param  list<array{name: string, domain: ?string}>  $competitors
     */
    public function __construct(
        public readonly ?string $ownDomain,
        public readonly array $competitors = [],
        public readonly string $city = '',
    ) {}

    public static function forHotel(Hotel $hotel): self
    {
        return new self(
            ownDomain: ScanFinalizer::domainOf($hotel->website),
            competitors: $hotel->competitors()->get(['name', 'website'])
                ->map(fn ($competitor) => [
                    'name' => (string) $competitor->name,
                    'domain' => ScanFinalizer::domainOf($competitor->website),
                ])->values()->all(),
            city: (string) $hotel->city,
        );
    }
}
