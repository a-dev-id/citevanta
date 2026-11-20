<?php

namespace App\Services\Engines;

/**
 * Optional context for an engine call. Real engines ignore it (they only see
 * the prompt, like a traveller would); the FakeEngine uses it to build
 * realistic, repeatable answers.
 */
final readonly class AskContext
{
    /**
     * @param  array<string, string|null>  $competitors  name => website
     */
    public function __construct(
        public string $hotelName,
        public string $city,
        public string $country,
        public ?string $hotelWebsite = null,
        public array $competitors = [],
        public int $runNumber = 1,
        /** The prompt's language (FakeEngine answers in it). */
        public string $language = 'en',
        /** @var list<string> other names of the hotel, e.g. in Chinese */
        public array $alternativeNames = [],
    ) {}
}
