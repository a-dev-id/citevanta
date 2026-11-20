<?php

namespace App\Services\Scanning;

final readonly class ScanRequest
{
    /**
     * @param  list<string>  $competitors
     */
    public function __construct(
        public string $hotelName,
        public string $city,
        public string $country,
        public array $competitors = [],
        public int $runs = 3,
        public string $language = 'en',
        public ?string $hotelType = null,
        public ?string $website = null,
        public int $promptCount = 10,
        // false: only add missing competitors; true: also remove the ones not listed.
        public bool $replaceCompetitors = false,
    ) {}
}
