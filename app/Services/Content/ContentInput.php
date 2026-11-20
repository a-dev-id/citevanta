<?php

namespace App\Services\Content;

use App\Enums\ContentType;

/**
 * Everything a piece of content may use: the hotel's own data and facts.
 * missingFacts are shown as "[add: ...]" placeholders, never invented.
 */
final class ContentInput
{
    /**
     * @param  array<string, string>  $facts  fact key => value (only filled facts)
     * @param  list<string>  $missingFacts  topics without a fact (lower case)
     * @param  array<string, string|null>  $hotel  name, city, country, website, type, stars
     */
    public function __construct(
        public readonly ContentType $type,
        public readonly string $language,
        public readonly string $languageName,
        public readonly array $hotel,
        public readonly array $facts,
        public readonly array $missingFacts,
        public readonly string $topic,
        public readonly string $why,
    ) {}

    /**
     * Text the content may take numbers and names from (for FactGuard).
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return [...array_values($this->facts), ...array_values(array_filter($this->hotel)), $this->topic, $this->why];
    }
}
