<?php

namespace App\Services\Prompts;

final readonly class PromptContext
{
    /**
     * @param  list<string>  $guestMarkets  countries the hotel's guests mainly come from
     */
    public function __construct(
        public string $hotelName,
        public string $city,
        public string $country,
        public ?string $hotelType = null,
        public string $language = 'en',
        public array $guestMarkets = [],
    ) {}
}
